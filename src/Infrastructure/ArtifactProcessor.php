<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\Kms\KmsClient;
use Aws\S3\S3Client;
use Aws\SesV2\SesV2Client;
use E7Propostas\WordPress\InvoiceService;
use E7Propostas\WordPress\ProposalRepository;

final class ArtifactProcessor
{
    private readonly ArtifactJobDispatcher $dispatcher;

    public function __construct(
        private readonly ProposalRepository $repository,
        private readonly FeatureFlags $features,
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceFinalizer $invoiceFinalizer,
    ) {
        $this->dispatcher = new ArtifactJobDispatcher(
            $this->finalizeAcceptance(...),
            $this->finalizeInvoice(...),
            $this->invoiceService->markFinalizationFailed(...),
        );
    }

    public function runDue(): int
    {
        global $wpdb;
        $jobs = $wpdb->prefix . 'e7_proposal_jobs';
        $now = current_time('mysql', true);
        $stale = gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $jobs WHERE (job_type='finalize_acceptance' OR job_type='finalize_invoice') AND ((status IN ('pending','retry') AND next_run_at <= %s) OR (status='processing' AND locked_at < %s)) ORDER BY id ASC LIMIT 5", $now, $stale), ARRAY_A);
        $processed = 0;
        foreach (is_array($rows) ? $rows : [] as $job) {
            $claimed = $wpdb->query($wpdb->prepare("UPDATE $jobs SET status='processing', locked_at=%s, updated_at=%s WHERE id=%d AND job_type=%s AND (status IN ('pending','retry') OR (status='processing' AND locked_at < %s))", $now, $now, (int) $job['id'], (string) $job['job_type'], $stale));
            if ($claimed !== 1) {
                continue;
            }
            try {
                $providerId = $this->dispatcher->dispatch($job);
                $wpdb->update($jobs, ['status' => 'completed', 'updated_at' => current_time('mysql', true), 'last_error' => null, 'provider_message_id' => $providerId], ['id' => (int) $job['id']]);
            } catch (\Throwable $error) {
                $attempts = (int) $job['attempts'] + 1;
                $terminal = $attempts >= 8;
                try {
                    $this->dispatcher->recordFailure($job, $error, $terminal);
                } catch (\Throwable $failureError) {
                    $error = new \RuntimeException($error->getMessage() . ' Invoice failure state: ' . $failureError->getMessage(), 0, $error);
                }
                $wpdb->update($jobs, [
                    'status' => $terminal ? 'failed' : 'retry',
                    'attempts' => $attempts,
                    'next_run_at' => gmdate('Y-m-d H:i:s', time() + min(21600, 60 * (2 ** $attempts))),
                    'updated_at' => current_time('mysql', true),
                    'last_error' => substr($error->getMessage(), 0, 2000),
                ], ['id' => (int) $job['id']]);
            }
            $processed++;
        }
        return $processed;
    }

    /** @param array<string, mixed> $job */
    private function finalizeAcceptance(array $job, array $payload): ?string
    {
        global $wpdb;
        $acceptance = $this->repository->findAcceptance((string) $payload['public_id']);
        if (! is_array($acceptance)) {
            throw new \RuntimeException('Acceptance no longer exists.');
        }
        if (wp_get_environment_type() === 'local') {
            if (! ArtifactState::hasEvent($acceptance['audit_events'] ?? [], 'artifact.local_evidence_created')) {
                $this->writeLocalEvidence($acceptance);
            }
            return 'local-evidence';
        }
        $bucket = getenv('E7_PROPOSTAS_S3_BUCKET');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        $version = $acceptance['version'];
        $pdf = null;

        if (ArtifactState::shouldGenerate($version)) {
            $renderer = getenv('E7_PROPOSTAS_RENDERER_URL');
            $kmsKey = getenv('E7_PROPOSTAS_KMS_SIGNING_KEY_ID');
            if (! is_string($renderer) || $renderer === '' || ! is_string($bucket) || $bucket === '' || ! is_string($kmsKey) || $kmsKey === '' || ! is_string($region) || $region === '') {
                throw new \RuntimeException('Artifact infrastructure is not configured.');
            }
            $html = $this->embedLocalImages($this->evidenceHtml($acceptance));
            $response = wp_remote_post($renderer, [
                'timeout' => 60,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['html' => $html, 'options' => ['printBackground' => true, 'format' => 'A4']]),
            ]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                throw new \RuntimeException('Chromium renderer failed.');
            }
            $pdf = wp_remote_retrieve_body($response);
            if (! str_starts_with($pdf, '%PDF-')) {
                throw new \RuntimeException('Renderer did not return a PDF.');
            }
            $hash = hash('sha256', $pdf);
            $kms = new KmsClient(['version' => 'latest', 'region' => $region]);
            $signature = $kms->sign(['KeyId' => $kmsKey, 'Message' => hex2bin($hash), 'MessageType' => 'DIGEST', 'SigningAlgorithm' => 'RSASSA_PSS_SHA_256']);
            $key = 'proposals/' . $acceptance['acceptance']['public_id'] . '.pdf';
            $s3 = new S3Client(['version' => 'latest', 'region' => $region]);
            $retentionYears = filter_var(getenv('E7_PROPOSTAS_RETENTION_YEARS') ?: '7', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            if (! is_int($retentionYears)) {
                throw new \RuntimeException('The artifact retention period is invalid.');
            }
            $put = $s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $pdf, 'ContentType' => 'application/pdf', 'ServerSideEncryption' => 'aws:kms', 'ObjectLockMode' => 'GOVERNANCE', 'ObjectLockRetainUntilDate' => gmdate(DATE_ATOM, strtotime('+' . $retentionYears . ' years'))]);
            $artifactKey = $key . '#' . (string) ($put->get('VersionId') ?? '');
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . 'e7_proposal_versions SET artifact_key = %s, artifact_hash = %s, kms_signature = %s WHERE id = %d AND (artifact_key IS NULL OR artifact_key = %s)',
                $artifactKey,
                $hash,
                base64_encode((string) $signature->get('Signature')),
                (int) $job['version_id'],
                '',
            ));
            if ($updated !== 1) {
                throw new \RuntimeException('Could not persist the final artifact without overwriting existing evidence.');
            }
            $version['artifact_key'] = $artifactKey;
            $version['artifact_hash'] = $hash;
            $version['kms_signature'] = base64_encode((string) $signature->get('Signature'));
        }

        $events = is_array($acceptance['audit_events'] ?? null) ? $acceptance['audit_events'] : [];
        if (! ArtifactState::hasEvent($events, 'artifact.finalized')) {
            $this->repository->appendAudit((int) $job['version_id'], 'artifact.finalized', [
                'artifact_hash' => (string) $version['artifact_hash'],
                'artifact_key' => (string) $version['artifact_key'],
            ]);
        }
        if (! $this->features->finalEmailEnabled()) {
            if (! ArtifactState::hasEvent($events, 'final_email.skipped')) {
                $this->repository->appendAudit((int) $job['version_id'], 'final_email.skipped', ['reason' => 'feature_disabled']);
            }
            return null;
        }

        $providerId = ArtifactState::providerMessageId($events);
        if ($providerId !== null) {
            return $providerId;
        }
        if ($pdf === null) {
            if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '') {
                throw new \RuntimeException('Artifact retrieval infrastructure is not configured.');
            }
            $pdf = $this->fetchPersistedPdf($version, $bucket, $region);
        }
        if (! $this->repository->claimFinalEmail((int) $job['version_id'])) {
            return null;
        }
        $providerId = $this->sendFinalEmail($acceptance, $pdf, $region);
        $this->repository->appendAudit((int) $job['version_id'], 'final_email.sent', ['provider_message_id' => $providerId]);
        return $providerId;
    }

    /** @param array<string, mixed> $job @param array{invoice_id: int, public_id: string} $payload */
    private function finalizeInvoice(array $job, array $payload): ?string
    {
        $invoice = $this->invoiceService->invoice($payload['invoice_id']);
        if (! hash_equals($payload['public_id'], (string) ($invoice['public_id'] ?? ''))) {
            throw new \DomainException('Invoice job identity does not match its immutable record.');
        }
        if (($invoice['status'] ?? null) === 'issued') {
            return null;
        }
        if (($invoice['status'] ?? null) !== 'processing') {
            throw new \DomainException('Invoice job can finalize only a processing invoice.');
        }
        $artifact = $this->invoiceFinalizer->finalize($invoice);
        $this->invoiceService->markIssued($payload['invoice_id'], $artifact);
        return wp_get_environment_type() === 'local' ? 'local-invoice-evidence' : null;
    }

    /** @param array<string, mixed> $version */
    private function fetchPersistedPdf(array $version, string $bucket, string $region): string
    {
        [$key, $versionId] = array_pad(explode('#', (string) $version['artifact_key'], 2), 2, '');
        $request = ['Bucket' => $bucket, 'Key' => $key];
        if ($versionId !== '') {
            $request['VersionId'] = $versionId;
        }
        $result = (new S3Client(['version' => 'latest', 'region' => $region]))->getObject($request);
        $pdf = (string) $result->get('Body');
        if (! str_starts_with($pdf, '%PDF-') || ! hash_equals((string) $version['artifact_hash'], hash('sha256', $pdf))) {
            throw new \RuntimeException('Persisted artifact failed its integrity check.');
        }
        return $pdf;
    }

    /** @param array<string, mixed> $record */
    private function writeLocalEvidence(array $record): void
    {
        global $wpdb;
        $directory = trailingslashit(dirname(ABSPATH)) . 'e7-propostas-private';
        wp_mkdir_p($directory);
        $file = $directory . '/' . $record['acceptance']['public_id'] . '.html';
        file_put_contents($file, $this->evidenceHtml($record), LOCK_EX);
        $updated = $wpdb->update($wpdb->prefix . 'e7_proposal_versions', ['artifact_key' => $file, 'artifact_hash' => hash_file('sha256', $file)], ['id' => (int) $record['version']['id']]);
        if ($updated !== 1 && $updated !== 0) {
            throw new \RuntimeException('Could not persist local evidence.');
        }
        $this->repository->appendAudit((int) $record['version']['id'], 'artifact.local_evidence_created', ['artifact_hash' => hash_file('sha256', $file)]);
    }

    /** @param array<string, mixed> $record */
    private function evidenceHtml(array $record): string
    {
        $version = $record['version'];
        $acceptance = $record['acceptance'];
        $manifest = json_decode((string) $version['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $metadata = is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [];
        $events = '';
        foreach ($record['audit_events'] ?? [] as $event) {
            $events .= '<tr><td>' . esc_html((string) $event['created_at']) . ' UTC</td><td>' . esc_html((string) $event['event_type']) . '</td><td>' . esc_html((string) $event['event_hash']) . '</td></tr>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><title>E7 Proposal</title><style>body{font:14px/1.55 Arial,sans-serif;color:#111;margin:36px}main{max-width:820px;margin:auto}img{max-width:100%}table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}code{word-break:break-all;font-size:10px}</style></head><body><main>'
            . wp_kses_post((string) $version['snapshot_html'])
            . '<hr><h2>Immutable proposal metadata</h2><dl><dt>Client</dt><dd>' . esc_html((string) ($metadata['client_name'] ?? '')) . '</dd><dt>Company</dt><dd>' . esc_html((string) ($metadata['client_company'] ?? '')) . '</dd><dt>Locale / currency</dt><dd>' . esc_html((string) ($metadata['locale'] ?? '') . ' / ' . (string) ($metadata['currency'] ?? '')) . '</dd><dt>Expiration</dt><dd>' . esc_html((string) ($metadata['expires_at'] ?? '')) . ' UTC</dd></dl>'
            . '<h2>Audit report</h2><dl><dt>Document</dt><dd>' . esc_html((string) $acceptance['public_id']) . '</dd><dt>Version</dt><dd>' . (int) $version['version_no'] . '</dd><dt>Document hash</dt><dd>' . esc_html((string) $version['document_hash']) . '</dd><dt>Accepted at</dt><dd>' . esc_html((string) $acceptance['accepted_at']) . ' UTC</dd><dt>Signer</dt><dd>' . esc_html((string) $acceptance['signer_name']) . '</dd><dt>Email</dt><dd>' . esc_html((string) $acceptance['signer_email']) . '</dd><dt>Phone</dt><dd>' . esc_html((string) $acceptance['signer_phone']) . '</dd><dt>Role / company</dt><dd>' . esc_html((string) $acceptance['signer_role'] . ' / ' . (string) $acceptance['signer_company']) . '</dd><dt>IP address</dt><dd>' . esc_html((string) $acceptance['ip_address']) . '</dd><dt>User agent</dt><dd>' . esc_html((string) $acceptance['user_agent']) . '</dd><dt>Consent</dt><dd>' . esc_html((string) $acceptance['consent_text']) . '</dd><dt>Audit hash</dt><dd>' . esc_html((string) $acceptance['audit_hash']) . '</dd></dl><table><thead><tr><th>UTC</th><th>Event</th><th>Hash</th></tr></thead><tbody>' . $events . '</tbody></table>'
            . '<h2>Canonical snapshot manifest</h2><p>This exact manifest is the input to the SHA-256 document hash above.</p><code>canonical snapshot manifest: ' . esc_html((string) $version['snapshot_json']) . '</code></main></body></html>';
    }

    /** @param array<string, mixed> $record */
    private function sendFinalEmail(array $record, string $pdf, string $region): string
    {
        $snapshot = json_decode((string) $record['version']['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $settings = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        $from = getenv('E7_SES_FROM_EMAIL');
        if (! is_string($from) || ! is_email($from)) {
            throw new \RuntimeException('SES sender is not configured.');
        }
        $recipients = array_values(array_unique(array_filter([(string) $record['acceptance']['signer_email'], (string) ($settings['copy_email'] ?? '')], 'is_email')));
        $email = EmailTemplate::finalCopy((string) ($settings['locale'] ?? 'pt_BR'));
        $boundary = 'e7-' . bin2hex(random_bytes(12));
        $alternativeBoundary = 'e7-alt-' . bin2hex(random_bytes(12));
        $raw = "From: E7 Company <$from>\r\nTo: " . implode(', ', $recipients) . "\r\nSubject: " . $email['subject'] . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n"
            . "--$boundary\r\nContent-Type: multipart/alternative; boundary=\"$alternativeBoundary\"\r\n\r\n"
            . "--$alternativeBoundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($email['text']))
            . "\r\n--$alternativeBoundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($email['html']))
            . "\r\n--$alternativeBoundary--\r\n"
            . "--$boundary\r\nContent-Type: application/pdf; name=proposal.pdf\r\nContent-Disposition: attachment; filename=proposal.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($pdf)) . "\r\n--$boundary--";
        $ses = new SesV2Client(['version' => 'latest', 'region' => $region]);
        $result = $ses->sendEmail(['FromEmailAddress' => $from, 'Destination' => ['ToAddresses' => $recipients], 'Content' => ['Raw' => ['Data' => $raw]]]);
        return (string) ($result->get('MessageId') ?? 'ses');
    }

    private function embedLocalImages(string $html): string
    {
        $uploads = wp_get_upload_dir();
        $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
        $baseDir = realpath((string) ($uploads['basedir'] ?? ''));
        if ($baseUrl === '' || $baseDir === false) {
            return $html;
        }
        return (string) preg_replace_callback("~(<img\\b[^>]*\\bsrc=[\"'])([^\"']+)([\"'])~i", static function (array $match) use ($baseUrl, $baseDir): string {
            $source = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! str_starts_with($source, $baseUrl . '/')) {
                return (string) $match[0];
            }
            $relative = ltrim(substr($source, strlen($baseUrl)), '/');
            $file = realpath($baseDir . '/' . $relative);
            if ($file === false || ! str_starts_with($file, $baseDir . DIRECTORY_SEPARATOR) || ! is_file($file) || filesize($file) > 10 * MB_IN_BYTES) {
                return (string) $match[0];
            }
            $mime = wp_check_filetype($file)['type'] ?? '';
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                return (string) $match[0];
            }
            $data = file_get_contents($file);
            return $data === false ? (string) $match[0] : (string) $match[1] . 'data:' . $mime . ';base64,' . base64_encode($data) . (string) $match[3];
        }, $html);
    }
}
