<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\AuditChain;
use E7Propostas\Domain\CanonicalPayload;
use E7Propostas\Domain\InvoiceItems;
use E7Propostas\Domain\OtpDestination;
use E7Propostas\Domain\SnapshotHasher;
use E7Propostas\Domain\ShareCodeService;
use E7Propostas\Domain\TokenService;
use E7Propostas\Infrastructure\Crypto;
use E7Propostas\Infrastructure\FinalEmailState;

final class ProposalRepository
{
    public function __construct(
        private readonly Crypto $crypto,
        private readonly TokenService $tokens,
        private readonly SnapshotHasher $snapshots,
        private readonly AuditChain $audit,
        private readonly ShareCodeService $shareCodes,
    ) {
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(int $postId, array $settings, ?string $passwordHash = null): void
    {
        global $wpdb;
        if ($this->isAcceptedPost($postId)) {
            throw new \DomainException('Accepted proposals are immutable and must be duplicated.');
        }
        $table = $this->table('e7_proposal_settings');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT password_hash, share_code FROM $table WHERE post_id = %d", $postId), ARRAY_A);
        $existingHash = is_array($existing) ? (string) $existing['password_hash'] : '';
        $existingCode = is_array($existing) && is_string($existing['share_code']) && $existing['share_code'] !== '' ? $existing['share_code'] : null;
        $email = sanitize_email((string) ($settings['client_email'] ?? ''));
        if (trim((string) ($settings['client_email'] ?? '')) !== '' && ! is_email($email)) {
            throw new \InvalidArgumentException('Informe um e-mail válido para o signatário ou deixe o campo vazio.');
        }
        $phone = trim(sanitize_text_field((string) ($settings['client_phone'] ?? '')));
        if ($phone !== '') {
            $phone = OtpDestination::from('sms', $phone)->value;
        }
        $invoiceItems = InvoiceItems::normalize($settings['invoice_items'] ?? []);
        $payload = [
            'client_name' => sanitize_text_field((string) ($settings['client_name'] ?? '')),
            'client_email' => $email,
            'client_phone' => $phone,
            'client_company' => sanitize_text_field((string) ($settings['client_company'] ?? '')),
            'copy_email' => sanitize_email((string) ($settings['copy_email'] ?? '')),
            'invoice_items' => $invoiceItems,
            'invoice_total_minor' => InvoiceItems::total($invoiceItems),
        ];
        $expiresAt = $this->sanitizeDate((string) ($settings['expires_at'] ?? ''));
        $locale = in_array(($settings['locale'] ?? ''), ['pt_BR', 'en_IE'], true) ? (string) $settings['locale'] : 'pt_BR';
        $currency = in_array(($settings['currency'] ?? ''), ['BRL', 'EUR', 'USD'], true) ? (string) $settings['currency'] : 'BRL';
        $policy = in_array(($settings['otp_policy'] ?? ''), ['email', 'sms', 'both'], true) ? (string) $settings['otp_policy'] : 'email';

        $this->mustWrite($wpdb->replace($table, [
            'post_id' => $postId,
            'share_code' => $existingCode,
            'client_payload' => $this->crypto->seal((string) wp_json_encode($payload)),
            'password_hash' => $passwordHash ?? $existingHash,
            'locale' => $locale,
            'currency' => $currency,
            'expires_at' => $expiresAt,
            'otp_policy' => $policy,
            'updated_at' => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']));
    }

    /** @return array<string, mixed> */
    public function getSettings(int $postId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_settings') . ' WHERE post_id = %d', $postId), ARRAY_A);
        if (! is_array($row)) {
            return [];
        }
        $payload = json_decode($this->crypto->open((string) $row['client_payload']), true, 512, JSON_THROW_ON_ERROR);
        return array_merge(is_array($payload) ? $payload : [], [
            'password_hash' => (string) $row['password_hash'],
            'share_code' => is_string($row['share_code']) ? $row['share_code'] : null,
            'locale' => (string) $row['locale'],
            'currency' => (string) $row['currency'],
            'expires_at' => $row['expires_at'],
            'otp_policy' => (string) $row['otp_policy'],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function publish(\WP_Post $post): ?array
    {
        global $wpdb;
        $settings = $this->getSettings($post->ID);
        if (($settings['password_hash'] ?? '') === '') {
            return null;
        }
        $locale = (string) ($settings['locale'] ?? 'pt_BR');
        $currency = (string) ($settings['currency'] ?? 'BRL');
        if (($locale === 'en_IE' && $currency === 'EUR') && empty($settings['invoice_items'])) {
            throw new \InvalidArgumentException('Irish EUR proposals require at least one invoice item before publishing.');
        }

        $this->ensureShareCode($post->ID);

        $html = do_blocks($post->post_content);
        $metadata = array_diff_key($settings, ['password_hash' => true, 'share_code' => true]);
        $metadata['title'] = $post->post_title;
        $snapshot = $this->snapshots->create($html, $metadata);
        $versions = $this->table('e7_proposal_versions');
        $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $versions WHERE post_id = %d ORDER BY version_no DESC LIMIT 1", $post->ID), ARRAY_A);
        if (is_array($latest) && hash_equals((string) $latest['document_hash'], $snapshot->hash)) {
            return $latest;
        }
        if (is_array($latest) && $latest['status'] === 'accepted') {
            return null;
        }

        $token = $this->tokens->generate();
        $next = is_array($latest) ? ((int) $latest['version_no'] + 1) : 1;
        $now = current_time('mysql', true);

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->update($versions, ['status' => 'superseded'], ['post_id' => $post->ID, 'status' => 'active'], ['%s'], ['%d', '%s']);
            if (is_array($latest)) {
                $wpdb->update($this->table('e7_proposal_sessions'), ['revoked_at' => $now], ['version_id' => (int) $latest['id']], ['%s'], ['%d']);
            }
            $this->mustWrite($wpdb->insert($versions, [
                'post_id' => $post->ID,
                'version_no' => $next,
                'snapshot_html' => $html,
                'snapshot_json' => $snapshot->canonicalJson,
                'document_hash' => $snapshot->hash,
                'token_hash' => $this->tokens->hash($token),
                'token_ciphertext' => $this->crypto->seal($token),
                'status' => 'active',
                'expires_at' => $settings['expires_at'] ?: null,
                'created_at' => $now,
            ]));
            $versionId = (int) $wpdb->insert_id;
            $this->appendAudit($versionId, 'proposal.published', ['version' => $next, 'document_hash' => $snapshot->hash]);
            $wpdb->query('COMMIT');
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }

        return $this->getVersion($versionId);
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_versions') . ' WHERE token_hash = %s LIMIT 1', $this->tokens->hash($token)), ARRAY_A);
        if (! is_array($row) || ! in_array($row['status'], ['active', 'accepted'], true)) {
            return null;
        }
        if ($row['status'] === 'active' && $this->isVersionExpired($row)) {
            return null;
        }
        return $row;
    }

    public function getShareCode(int $postId): ?string
    {
        global $wpdb;
        $code = $wpdb->get_var($wpdb->prepare(
            'SELECT share_code FROM ' . $this->table('e7_proposal_settings') . ' WHERE post_id = %d',
            $postId,
        ));
        return is_string($code) && $code !== '' ? strtolower($code) : null;
    }

    public function restoreShareCode(int $postId, string $code, bool $replaceIncompleteImport = false): void
    {
        global $wpdb;
        $normalized = $this->shareCodes->normalize($code);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid proposal share code.');
        }

        $table = $this->table('e7_proposal_settings');
        $owner = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $table WHERE LOWER(share_code) = %s LIMIT 1", $normalized));
        if ($owner !== null && (int) $owner !== $postId) {
            throw new \DomainException('Share code is already assigned to another proposal.');
        }

        $current = $wpdb->get_var($wpdb->prepare("SELECT share_code FROM $table WHERE post_id = %d LIMIT 1", $postId));
        if (is_string($current) && $current !== '') {
            if (! hash_equals(strtolower($current), $normalized)) {
                if (! ($replaceIncompleteImport && ! $this->isAcceptedPost($postId))) {
                    throw new \DomainException('An imported proposal cannot change its stable share code.');
                }
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET share_code = %s WHERE post_id = %d AND share_code = %s",
                    $normalized,
                    $postId,
                    $current,
                ));
                if ($updated !== 1) {
                    throw new \RuntimeException('Could not replace the incomplete import share code.');
                }
            }
            return;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET share_code = %s WHERE post_id = %d AND share_code IS NULL",
            $normalized,
            $postId,
        ));
        if ($updated !== 1) {
            $owner = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $table WHERE LOWER(share_code) = %s LIMIT 1", $normalized));
            if ($owner !== null && (int) $owner !== $postId) {
                throw new \DomainException('Share code is already assigned to another proposal.');
            }
            throw new \RuntimeException('Could not restore the proposal share code.');
        }
    }

    /** @return array<string, mixed>|null */
    public function findCurrentByShareCode(string $code): ?array
    {
        global $wpdb;
        $normalized = $this->shareCodes->normalize($code);
        if ($normalized === null) {
            return null;
        }

        $postId = $wpdb->get_var($wpdb->prepare(
            'SELECT post_id FROM ' . $this->table('e7_proposal_settings') . ' WHERE LOWER(share_code) = %s LIMIT 1',
            $normalized,
        ));
        if (! is_numeric($postId)) {
            return null;
        }

        $versions = $this->table('e7_proposal_versions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $versions WHERE post_id = %d AND status IN ('active','accepted') ORDER BY version_no DESC LIMIT 1",
            (int) $postId,
        ), ARRAY_A);
        if (! is_array($row) || ($row['status'] === 'active' && $this->isVersionExpired($row))) {
            return null;
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function getVersion(int $versionId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_versions') . ' WHERE id = %d', $versionId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $version */
    public function isVersionExpired(array $version): bool
    {
        return ! empty($version['expires_at']) && strtotime((string) $version['expires_at'] . ' UTC') < time();
    }

    public function isAcceptedPost(int $postId): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . $this->table('e7_proposal_versions') . ' WHERE post_id = %d ORDER BY version_no DESC LIMIT 1',
            $postId,
        )) === 'accepted';
    }

    /** @return array<string, mixed>|null */
    public function latestForPost(int $postId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_versions') . ' WHERE post_id = %d ORDER BY version_no DESC LIMIT 1', $postId), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $row['public_token'] = $this->crypto->open((string) $row['token_ciphertext']);
        return $row;
    }

    public function createSession(int $versionId): string
    {
        global $wpdb;
        $raw = $this->tokens->generate();
        $this->mustWrite($wpdb->insert($this->table('e7_proposal_sessions'), [
            'version_id' => $versionId,
            'session_hash' => $this->tokens->hash($raw),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS),
            'authorized_at' => current_time('mysql', true),
        ]));
        return $raw;
    }

    /** @return array<string, mixed>|null */
    public function findSession(string $raw): ?array
    {
        global $wpdb;
        if (! preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_sessions') . ' WHERE session_hash = %s LIMIT 1', $this->tokens->hash($raw)), ARRAY_A);
        if (! is_array($row) || $row['revoked_at'] !== null || strtotime((string) $row['expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $row;
    }

    public function registerRateFailure(string $scope, int $maxAttempts = 5, int $blockSeconds = 15 * MINUTE_IN_SECONDS): int
    {
        global $wpdb;
        $table = $this->table('e7_proposal_rate_limits');
        $scopeHash = hash('sha256', $scope);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE scope_hash = %s", $scopeHash), ARRAY_A);
        $now = current_time('mysql', true);
        if (! is_array($row) || strtotime((string) $row['window_started_at'] . ' UTC') < time() - HOUR_IN_SECONDS) {
            $wpdb->replace($table, ['scope_hash' => $scopeHash, 'window_started_at' => $now, 'attempt_count' => 1, 'blocked_until' => null]);
            return 1;
        }
        $count = (int) $row['attempt_count'] + 1;
        $blocked = $count >= $maxAttempts ? gmdate('Y-m-d H:i:s', time() + $blockSeconds) : null;
        $wpdb->update($table, ['attempt_count' => $count, 'blocked_until' => $blocked], ['id' => (int) $row['id']]);
        return $count;
    }

    public function isRateBlocked(string $scope): bool
    {
        global $wpdb;
        $blocked = $wpdb->get_var($wpdb->prepare('SELECT blocked_until FROM ' . $this->table('e7_proposal_rate_limits') . ' WHERE scope_hash = %s', hash('sha256', $scope)));
        return is_string($blocked) && $blocked !== '' && strtotime($blocked . ' UTC') > time();
    }

    public function clearRate(string $scope): void
    {
        global $wpdb;
        $wpdb->delete($this->table('e7_proposal_rate_limits'), ['scope_hash' => hash('sha256', $scope)]);
    }

    public function saveOtp(int $sessionId, int $versionId, string $channel, string $destination, string $hash, string $expiresAt, string $providerId): int
    {
        global $wpdb;
        $wpdb->insert($this->table('e7_proposal_otps'), [
            'session_id' => $sessionId,
            'version_id' => $versionId,
            'channel' => $channel,
            'destination' => $destination,
            'code_hash' => $hash,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'provider_message_id' => $providerId,
            'created_at' => current_time('mysql', true),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    public function latestOtp(int $sessionId, int $versionId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_otps') . ' WHERE session_id = %d AND version_id = %d ORDER BY id DESC LIMIT 1', $sessionId, $versionId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function updateOtp(int $otpId, int $attempts, bool $consumed): void
    {
        global $wpdb;
        $wpdb->update($this->table('e7_proposal_otps'), [
            'attempts' => $attempts,
            'consumed_at' => $consumed ? current_time('mysql', true) : null,
        ], ['id' => $otpId]);
    }

    public function recordOtpFailure(int $otpId, int $expectedAttempts): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            'UPDATE ' . $this->table('e7_proposal_otps') . ' SET attempts = attempts + 1 WHERE id = %d AND attempts = %d AND consumed_at IS NULL',
            $otpId,
            $expectedAttempts,
        )) === 1;
    }

    public function withOtpSendLock(int $versionId, callable $operation): mixed
    {
        return $this->withNamedOtpLock($versionId, $operation);
    }

    public function withOtpLock(int $versionId, callable $operation): mixed
    {
        return $this->withNamedOtpLock($versionId, $operation);
    }

    private function withNamedOtpLock(int $versionId, callable $operation): mixed
    {
        global $wpdb;
        $lock = substr('e7-otp-version-' . get_current_blog_id() . '-' . $versionId, 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            throw new \RuntimeException('Could not reserve OTP delivery.');
        }
        try {
            return $operation();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** @return array<string, mixed>|null */
    public function findAcceptanceByIdempotency(int $versionId, string $idempotencyKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_acceptances') . ' WHERE version_id = %d AND idempotency_key = %s', $versionId, $idempotencyKey), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, string> $signer @param array<string, mixed>|null $businessProfile @return array<string, mixed> */
    public function accept(int $versionId, ?int $otpId, ?int $otpAttempts, string $idempotencyKey, array $signer, string $consent, string $ip, string $userAgent, ?array $businessProfile = null, bool $otpRequired = true): array
    {
        global $wpdb;
        $versions = $this->table('e7_proposal_versions');
        $acceptances = $this->table('e7_proposal_acceptances');
        $auditLock = $this->acquireAuditLock($versionId);
        $wpdb->query('START TRANSACTION');
        try {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $acceptances WHERE version_id = %d AND idempotency_key = %s", $versionId, $idempotencyKey), ARRAY_A);
            if (is_array($existing)) {
                $wpdb->query('COMMIT');
                return $existing;
            }
            $version = $wpdb->get_row($wpdb->prepare("SELECT * FROM $versions WHERE id = %d FOR UPDATE", $versionId), ARRAY_A);
            if (! is_array($version) || $version['status'] !== 'active' || $this->isVersionExpired($version)) {
                throw new \DomainException('Proposal is not available for acceptance.');
            }
            if ($otpRequired) {
                if ($otpId === null || $otpAttempts === null) {
                    throw new \DomainException('OTP details are required.');
                }
                $consumed = $wpdb->query($wpdb->prepare(
                    'UPDATE ' . $this->table('e7_proposal_otps') . ' SET consumed_at = %s WHERE id = %d AND attempts = %d AND consumed_at IS NULL',
                    current_time('mysql', true),
                    $otpId,
                    $otpAttempts,
                ));
                if ($consumed !== 1) {
                    throw new \DomainException('Could not consume OTP atomically.');
                }
            }
            $publicId = bin2hex(random_bytes(16));
            $acceptedAt = current_time('mysql', true);
            $snapshot = json_decode((string) $version['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $metadata = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
            $businessProfileHash = $businessProfile === null ? null : CanonicalPayload::hash($businessProfile);
            $invoiceTotal = (int) ($metadata['invoice_total_minor'] ?? 0);
            $versionNumber = (int) $version['version_no'];
            $acceptanceContextHash = CanonicalPayload::hash([
                'business_profile' => $businessProfile,
                'invoice_total_minor' => $invoiceTotal,
                'version' => $versionNumber,
            ]);
            // Crypto::seal authenticates the ciphertext; this audited canonical hash binds
            // the plaintext to its proposal/invoice context without changing Crypto's API.
            $sealedBusinessProfile = $businessProfile === null ? null : $this->crypto->seal(CanonicalPayload::encode($businessProfile));
            if ($otpRequired) {
                $this->appendAudit($versionId, 'otp.verified', ['otp_id' => $otpId], true);
            } else {
                $this->appendAudit($versionId, 'authentication.verified', ['method' => 'password_session'], true);
            }
            $auditHash = $this->appendAudit($versionId, 'proposal.accepted', [
                'document_hash' => $version['document_hash'],
                'accepted_at' => $acceptedAt,
                'signer_name' => $signer['name'],
                'consent' => $consent,
                'business_profile_hash' => $businessProfileHash,
                'acceptance_context_hash' => $acceptanceContextHash,
                'invoice_total_minor' => $invoiceTotal,
                'version' => $versionNumber,
            ], true);
            $this->mustWrite($wpdb->insert($acceptances, [
                'version_id' => $versionId,
                'public_id' => $publicId,
                'idempotency_key' => $idempotencyKey,
                'signer_name' => $signer['name'],
                'signer_role' => $signer['role'],
                'signer_company' => $signer['company'],
                'signer_email' => $signer['email'],
                'signer_phone' => $signer['phone'],
                'business_payload' => $sealedBusinessProfile,
                'consent_text' => $consent,
                'accepted_at' => $acceptedAt,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'audit_hash' => $auditHash,
            ]));
            $acceptanceId = (int) $wpdb->insert_id;
            $this->mustWrite($wpdb->update($versions, ['status' => 'accepted', 'accepted_at' => $acceptedAt], ['id' => $versionId, 'status' => 'active']));
            $payload = wp_json_encode(['acceptance_id' => $acceptanceId, 'public_id' => $publicId]);
            $this->mustWrite($wpdb->insert($this->table('e7_proposal_jobs'), [
                'version_id' => $versionId,
                'job_type' => 'finalize_acceptance',
                'status' => 'pending',
                'attempts' => 0,
                'next_run_at' => $acceptedAt,
                'payload' => $payload,
                'created_at' => $acceptedAt,
                'updated_at' => $acceptedAt,
            ]));
            $wpdb->query('COMMIT');
            $created = $wpdb->get_row($wpdb->prepare("SELECT * FROM $acceptances WHERE id = %d", $acceptanceId), ARRAY_A);
            if (! is_array($created)) {
                throw new \RuntimeException('Database read failed after acceptance.');
            }
            return $created;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        } finally {
            $this->releaseAuditLock($auditLock);
        }
    }

    /** @return array<string, mixed>|null */
    public function findAcceptance(string $publicId): ?array
    {
        global $wpdb;
        $acceptance = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('e7_proposal_acceptances') . ' WHERE public_id = %s', $publicId), ARRAY_A);
        if (! is_array($acceptance)) {
            return null;
        }
        $version = $this->getVersion((int) $acceptance['version_id']);
        if (! is_array($version)) {
            return null;
        }
        $auditEvents = $wpdb->get_results($wpdb->prepare('SELECT event_type, payload, previous_hash, event_hash, created_at FROM ' . $this->table('e7_proposal_audit_events') . ' WHERE version_id = %d ORDER BY id ASC', (int) $version['id']), ARRAY_A);
        return ['acceptance' => $acceptance, 'version' => $version, 'audit_events' => is_array($auditEvents) ? $auditEvents : []];
    }

    /** @return array<string, mixed>|null */
    public function findAcceptanceByVersion(int $versionId): ?array
    {
        global $wpdb;
        $publicId = $wpdb->get_var($wpdb->prepare('SELECT public_id FROM ' . $this->table('e7_proposal_acceptances') . ' WHERE version_id = %d', $versionId));
        return is_string($publicId) ? $this->findAcceptance($publicId) : null;
    }

    public function claimFinalEmail(int $versionId): bool
    {
        global $wpdb;
        $lock = $this->acquireAuditLock($versionId);
        try {
            $table = $this->table('e7_proposal_audit_events');
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_type FROM $table WHERE version_id = %d AND event_type IN ('final_email.claimed','final_email.sent') ORDER BY id ASC",
                $versionId,
            ), ARRAY_A);
            $state = FinalEmailState::fromEvents(is_array($rows) ? $rows : []);
            if (! $state->claim()) {
                return false;
            }
            $this->appendAudit($versionId, 'final_email.claimed', ['claimed_at' => current_time('mysql', true)], true);
            return true;
        } finally {
            $this->releaseAuditLock($lock);
        }
    }

    /** @param array<string, mixed> $payload */
    public function appendAudit(int $versionId, string $type, array $payload, bool $lockHeld = false): string
    {
        global $wpdb;
        $table = $this->table('e7_proposal_audit_events');
        $lock = $lockHeld ? '' : $this->acquireAuditLock($versionId);
        try {
            $previous = $wpdb->get_var($wpdb->prepare("SELECT event_hash FROM $table WHERE version_id = %d ORDER BY id DESC LIMIT 1", $versionId));
            $event = ['type' => $type, 'occurred_at' => gmdate(DATE_ATOM), 'payload' => $payload];
            $hash = $this->audit->next(is_string($previous) ? $previous : null, $event);
            $this->mustWrite($wpdb->insert($table, [
                'version_id' => $versionId,
                'event_type' => $type,
                'payload' => wp_json_encode($payload),
                'previous_hash' => $previous ?: null,
                'event_hash' => $hash,
                'created_at' => current_time('mysql', true),
            ]));
            return $hash;
        } finally {
            if (! $lockHeld) {
                $this->releaseAuditLock($lock);
            }
        }
    }

    public function withAuditLock(int $versionId, callable $operation): mixed
    {
        $lock = $this->acquireAuditLock($versionId);
        try {
            return $operation();
        } finally {
            $this->releaseAuditLock($lock);
        }
    }

    private function acquireAuditLock(int $versionId): string
    {
        global $wpdb;
        $lock = substr('e7-audit-' . get_current_blog_id() . '-' . $versionId, 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            throw new \RuntimeException('Could not serialize the audit trail.');
        }
        return $lock;
    }

    private function releaseAuditLock(string $lock): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    private function ensureShareCode(int $postId): string
    {
        global $wpdb;
        $existing = $this->getShareCode($postId);
        if ($existing !== null) {
            return $existing;
        }

        $table = $this->table('e7_proposal_settings');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $this->shareCodes->generateUnique(static function (string $candidate) use ($wpdb, $table): bool {
                return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE share_code = %s LIMIT 1", $candidate));
            });
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET share_code = %s WHERE post_id = %d AND share_code IS NULL",
                $code,
                $postId,
            ));
            if ($updated === 1) {
                return $code;
            }

            $existing = $this->getShareCode($postId);
            if ($existing !== null) {
                return $existing;
            }
        }

        throw new \RuntimeException('Could not persist a unique proposal share code.');
    }

    private function sanitizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value . ' 23:59:59 UTC');
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function mustWrite(int|false $result): void
    {
        if ($result === false || $result < 1) {
            global $wpdb;
            throw new \RuntimeException('Database write failed: ' . (string) $wpdb->last_error);
        }
    }
}
