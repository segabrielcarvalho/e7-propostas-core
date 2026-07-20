<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\CanonicalPayload;
use E7Propostas\Domain\InvoiceNumber;
use E7Propostas\Domain\InvoiceSnapshot;
use E7Propostas\Infrastructure\Crypto;
use E7Propostas\Infrastructure\InvoiceSignatureEnvelope;

final class InvoiceRepository implements InvoiceStore
{
    public function __construct(private readonly Crypto $crypto, private readonly ProposalRepository $proposals)
    {
    }

    public function acceptanceContext(int $acceptanceId): array
    {
        global $wpdb;
        $acceptances = $this->table('e7_proposal_acceptances');
        $versions = $this->table('e7_proposal_versions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, v.snapshot_json, v.post_id FROM $acceptances a INNER JOIN $versions v ON v.id=a.version_id WHERE a.id=%d",
            $acceptanceId,
        ), ARRAY_A);
        if (! is_array($row)) {
            throw new \DomainException('Acceptance was not found.');
        }
        $snapshot = json_decode((string) $row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $metadata = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        return [
            'acceptance_id' => $acceptanceId,
            'version_id' => (int) $row['version_id'],
            'post_id' => (int) $row['post_id'],
            'locale' => (string) ($metadata['locale'] ?? ''),
            'currency' => (string) ($metadata['currency'] ?? ''),
            'customer_profile' => $this->openNullableProfile($row['business_payload'] ?? null),
            'invoice_items' => is_array($metadata['invoice_items'] ?? null) ? $metadata['invoice_items'] : [],
            'invoice_total_minor' => (int) ($metadata['invoice_total_minor'] ?? 0),
        ];
    }

    public function currentRoot(int $acceptanceId): ?array
    {
        global $wpdb;
        $table = $this->table('e7_proposal_invoices');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE acceptance_id=%d AND replacement_for_id IS NULL ORDER BY id DESC LIMIT 1",
            $acceptanceId,
        ), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function get(int $invoiceId): ?array
    {
        global $wpdb;
        $table = $this->table('e7_proposal_invoices');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $invoiceId), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function verifiedInvoice(int $invoiceId): array
    {
        global $wpdb;
        $table = $this->table('e7_proposal_invoices');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $invoiceId), ARRAY_A);
        if (! is_array($row)) {
            throw new \DomainException('Invoice was not found.');
        }
        $this->assertSnapshotIntegrity($row);
        return $this->hydrate($row);
    }

    public function findByPublicId(string $publicId): ?array
    {
        global $wpdb;
        if (! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }
        $table = $this->table('e7_proposal_invoices');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, replacement.status AS replacement_status, replacement.invoice_number AS replacement_invoice_number FROM $table i LEFT JOIN $table replacement ON replacement.replacement_for_id=i.id WHERE i.public_id=%s LIMIT 1",
            $publicId,
        ), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $this->assertSnapshotIntegrity($row);
        return $this->hydrate($row);
    }

    public function latestIssuedForVersion(int $versionId): ?array
    {
        global $wpdb;
        $table = $this->table('e7_proposal_invoices');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE version_id=%d AND status='issued' ORDER BY id DESC LIMIT 1", $versionId), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $this->assertSnapshotIntegrity($row);
        return $this->hydrate($row);
    }

    public function findByPost(int $postId): ?array
    {
        global $wpdb;
        $invoices = $this->table('e7_proposal_invoices');
        $versions = $this->table('e7_proposal_versions');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT i.* FROM $invoices i INNER JOIN $versions v ON v.id=i.version_id WHERE v.post_id=%d ORDER BY i.id DESC LIMIT 1",
            $postId,
        ), ARRAY_A);
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function acceptanceIdForPost(int $postId): ?int
    {
        global $wpdb;
        $acceptances = $this->table('e7_proposal_acceptances');
        $versions = $this->table('e7_proposal_versions');
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT a.id FROM $acceptances a INNER JOIN $versions v ON v.id=a.version_id WHERE v.post_id=%d ORDER BY a.id DESC LIMIT 1",
            $postId,
        ));
        return is_numeric($id) ? (int) $id : null;
    }

    public function createDraft(array $snapshot): array
    {
        global $wpdb;
        $acceptanceId = (int) $snapshot['acceptance_id'];
        return $this->withLock('e7-invoice-acceptance-' . $acceptanceId, function () use ($wpdb, $snapshot, $acceptanceId): array {
            $existing = $this->currentRoot($acceptanceId);
            if (is_array($existing)) {
                return $existing;
            }
            $now = current_time('mysql', true);
            $publicId = bin2hex(random_bytes(16));
            $snapshotHash = InvoiceSnapshot::hash(
                $publicId,
                $acceptanceId,
                (int) $snapshot['version_id'],
                (string) $snapshot['currency'],
                (int) $snapshot['total_minor'],
                (array) $snapshot['customer_profile'],
                (array) $snapshot['supplier_profile'],
                (array) $snapshot['items'],
            );
            $this->mustWrite($wpdb->insert($this->table('e7_proposal_invoices'), [
                'acceptance_id' => $acceptanceId,
                'version_id' => (int) $snapshot['version_id'],
                'public_id' => $publicId,
                'invoice_number' => null,
                'currency' => (string) $snapshot['currency'],
                'customer_payload' => $this->seal((array) $snapshot['customer_profile']),
                'supplier_payload' => $this->seal((array) $snapshot['supplier_profile']),
                'items_payload' => $this->seal((array) $snapshot['items']),
                'subtotal_minor' => (int) $snapshot['total_minor'],
                'total_minor' => (int) $snapshot['total_minor'],
                'status' => 'draft',
                'legacy_backfill_required' => ! empty($snapshot['legacy_backfill_required']) ? 1 : 0,
                'snapshot_hash' => $snapshotHash,
                'vies_status' => 'not_requested',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $invoice = $this->get((int) $wpdb->insert_id);
            if (! is_array($invoice)) {
                throw new \RuntimeException('Invoice could not be read after creation.');
            }
            return $invoice;
        });
    }

    public function updateDraftCustomer(int $invoiceId, array $profile): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId, $profile): array {
            $table = $this->table('e7_proposal_invoices');
            $wpdb->query('START TRANSACTION');
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                if (! is_array($row) || $row['status'] !== 'draft' || (int) $row['legacy_backfill_required'] === 1) {
                    throw new \DomainException('Draft customer data could not be updated.');
                }
                $invoice = $this->hydrate($row);
                $snapshotHash = InvoiceSnapshot::hash((string) $row['public_id'], (int) $row['acceptance_id'], (int) $row['version_id'], (string) $row['currency'], (int) $row['total_minor'], $profile, $invoice['supplier_profile'], $invoice['items']);
                $this->mustWrite($wpdb->update($table, [
                    'customer_payload' => $this->seal($profile),
                    'snapshot_hash' => $snapshotHash,
                    'vies_status' => 'not_requested',
                    'vies_checked_at' => null,
                    'vies_evidence' => null,
                    'updated_at' => current_time('mysql', true),
                ], ['id' => $invoiceId, 'status' => 'draft', 'legacy_backfill_required' => 0]));
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($invoiceId);
        });
    }

    public function issueAndEnqueue(int $invoiceId): array
    {
        global $wpdb;
        $year = (int) gmdate('Y');
        return $this->withLock('e7-invoice-sequence-' . $year, function () use ($wpdb, $invoiceId, $year): array {
            $wpdb->query('START TRANSACTION');
            try {
                $table = $this->table('e7_proposal_invoices');
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                if (! is_array($invoice) || $invoice['status'] !== 'draft') {
                    throw new \DomainException('Only a draft invoice can be issued.');
                }
                $this->assertSnapshotIntegrity($invoice);
                $number = is_string($invoice['invoice_number'] ?? null) && $invoice['invoice_number'] !== '' ? $invoice['invoice_number'] : null;
                if ($number === null) {
                    $number = $this->reserveNumber($year);
                }
                $now = current_time('mysql', true);
                $this->mustWrite($wpdb->update($table, [
                    'invoice_number' => $number,
                    'status' => 'processing',
                    'due_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ], ['id' => $invoiceId, 'status' => 'draft']));
                $this->insertFinalizeJob($invoice, $now);
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($invoiceId);
        });
    }

    public function backfillLegacy(int $invoiceId, array $profile, array $items, int $totalMinor, int $actorId): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId, $profile, $items, $totalMinor, $actorId): array {
            $candidate = $this->requireInvoice($invoiceId);
            return $this->proposals->withAuditLock((int) $candidate['version_id'], function () use ($wpdb, $invoiceId, $profile, $items, $totalMinor, $actorId): array {
                $table = $this->table('e7_proposal_invoices');
                $wpdb->query('START TRANSACTION');
                try {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                    if (! is_array($row) || $row['status'] !== 'draft' || (int) $row['legacy_backfill_required'] !== 1) {
                        throw new \DomainException('Legacy invoice backfill is no longer available.');
                    }
                    $supplier = $this->openArray((string) $row['supplier_payload']);
                    $snapshotHash = InvoiceSnapshot::hash((string) $row['public_id'], (int) $row['acceptance_id'], (int) $row['version_id'], (string) $row['currency'], $totalMinor, $profile, $supplier, $items);
                    $this->mustWrite($wpdb->update($table, [
                        'customer_payload' => $this->seal($profile),
                        'items_payload' => $this->seal($items),
                        'subtotal_minor' => $totalMinor,
                        'total_minor' => $totalMinor,
                        'legacy_backfill_required' => 0,
                        'snapshot_hash' => $snapshotHash,
                        'updated_at' => current_time('mysql', true),
                    ], ['id' => $invoiceId, 'status' => 'draft', 'legacy_backfill_required' => 1]));
                    $this->proposals->appendAudit((int) $row['version_id'], 'invoice.legacy_backfill_confirmed', [
                        'invoice_id' => $invoiceId,
                        'acceptance_id' => (int) $row['acceptance_id'],
                        'actor_id' => $actorId,
                        'items_total_minor' => $totalMinor,
                        'snapshot_hash' => $snapshotHash,
                    ], true);
                    $wpdb->query('COMMIT');
                } catch (\Throwable $error) {
                    $wpdb->query('ROLLBACK');
                    throw $error;
                }
                return $this->requireInvoice($invoiceId);
            });
        });
    }

    public function markFailed(int $invoiceId, string $message): void
    {
        global $wpdb;
        $this->mustWrite($wpdb->update($this->table('e7_proposal_invoices'), [
            'status' => 'failed',
            'last_error' => substr($message, 0, 2000),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $invoiceId, 'status' => 'processing']));
    }

    public function persistArtifact(int $invoiceId, array $artifact): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId, $artifact): array {
            $table = $this->table('e7_proposal_invoices');
            $key = trim((string) ($artifact['artifact_key'] ?? ''));
            $hash = strtolower(trim((string) ($artifact['artifact_hash'] ?? '')));
            $signature = isset($artifact['kms_signature']) && $artifact['kms_signature'] !== '' ? (string) $artifact['kms_signature'] : null;
            $payloadHash = strtolower(trim((string) ($artifact['signature_payload_hash'] ?? '')));
            $issuedAt = trim((string) ($artifact['issued_at'] ?? ''));
            if ($key === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash) || ! preg_match('/^[a-f0-9]{64}$/', $payloadHash) || $issuedAt === '') {
                throw new \InvalidArgumentException('Invoice artifact evidence is invalid.');
            }
            $wpdb->query('START TRANSACTION');
            try {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                if (! is_array($row) || $row['status'] !== 'processing') {
                    throw new \DomainException('Only a processing invoice can persist an artifact.');
                }
                $this->assertSnapshotIntegrity($row);
                $invoice = $this->hydrate($row);
                if (! hash_equals((string) ($row['due_at'] ?? ''), $issuedAt)
                    || ! hash_equals(InvoiceSignatureEnvelope::hash(array_merge($invoice, ['artifact_hash' => $hash, 'issued_at' => $issuedAt])), $payloadHash)) {
                    throw new \DomainException('Invoice signature envelope failed integrity validation.');
                }
                $existingKey = trim((string) ($row['artifact_key'] ?? ''));
                $existingHash = strtolower(trim((string) ($row['artifact_hash'] ?? '')));
                $existingSignature = isset($row['kms_signature']) && $row['kms_signature'] !== '' ? (string) $row['kms_signature'] : null;
                $existingPayloadHash = strtolower(trim((string) ($row['signature_payload_hash'] ?? '')));
                $existingIssuedAt = trim((string) ($row['issued_at'] ?? ''));
                if ($existingKey !== '' || $existingHash !== '' || $existingSignature !== null || $existingPayloadHash !== '' || $existingIssuedAt !== '') {
                    if ($existingKey !== $key || ! hash_equals($existingHash, $hash) || $existingSignature !== $signature || ! hash_equals($existingPayloadHash, $payloadHash) || $existingIssuedAt !== $issuedAt) {
                        throw new \DomainException('Conflicting or partial invoice artifact evidence already exists.');
                    }
                    $wpdb->query('COMMIT');
                    return $this->hydrate($row);
                }
                $this->mustWrite($wpdb->update($table, [
                    'artifact_key' => $key,
                    'artifact_hash' => $hash,
                    'signature_payload_hash' => $payloadHash,
                    'kms_signature' => $signature,
                    'issued_at' => $issuedAt,
                    'updated_at' => current_time('mysql', true),
                ], ['id' => $invoiceId, 'status' => 'processing']));
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($invoiceId);
        });
    }

    public function retryAndEnqueue(int $invoiceId): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId): array {
            $table = $this->table('e7_proposal_invoices');
            $wpdb->query('START TRANSACTION');
            try {
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                if (! is_array($invoice) || $invoice['status'] !== 'failed' || ! is_string($invoice['invoice_number']) || $invoice['invoice_number'] === '') {
                    throw new \DomainException('Only a numbered failed invoice can be retried.');
                }
                $this->assertSnapshotIntegrity($invoice);
                $now = current_time('mysql', true);
                $this->mustWrite($wpdb->update($table, ['status' => 'processing', 'last_error' => null, 'updated_at' => $now], ['id' => $invoiceId, 'status' => 'failed']));
                $this->resetFinalizeJob($invoice, $now);
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($invoiceId);
        });
    }

    public function cancel(int $invoiceId, int $actorId): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId, $actorId): array {
            $candidate = $this->requireInvoice($invoiceId);
            return $this->proposals->withAuditLock((int) $candidate['version_id'], function () use ($wpdb, $invoiceId, $actorId): array {
                $table = $this->table('e7_proposal_invoices');
                $wpdb->query('START TRANSACTION');
                try {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                    if (! is_array($row) || $row['status'] !== 'issued') {
                        throw new \DomainException('Only an issued invoice can be cancelled.');
                    }
                    $now = current_time('mysql', true);
                    $this->mustWrite($wpdb->update($table, [
                        'status' => 'cancelled',
                        'cancelled_at' => $now,
                        'updated_at' => $now,
                    ], ['id' => $invoiceId, 'status' => 'issued']));
                    $this->proposals->appendAudit((int) $row['version_id'], 'invoice.cancelled', ['invoice_id' => $invoiceId, 'actor_id' => $actorId], true);
                    $wpdb->query('COMMIT');
                } catch (\Throwable $error) {
                    $wpdb->query('ROLLBACK');
                    throw $error;
                }
                return $this->requireInvoice($invoiceId);
            });
        });
    }

    public function createReplacement(int $invoiceId, int $actorId): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-replacement-' . $invoiceId, function () use ($wpdb, $invoiceId, $actorId): array {
            $candidate = $this->requireInvoice($invoiceId);
            return $this->proposals->withAuditLock((int) $candidate['version_id'], function () use ($wpdb, $invoiceId, $actorId): array {
            $table = $this->table('e7_proposal_invoices');
            $wpdb->query('START TRANSACTION');
            try {
                $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                if (! is_array($source) || ! in_array($source['status'], ['issued', 'cancelled'], true)) {
                    throw new \DomainException('Invoice cannot be replaced.');
                }
                $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE replacement_for_id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                $now = current_time('mysql', true);
                if (is_array($existing)) {
                    if (empty($source['replaced_at'])) {
                        $this->mustWrite($wpdb->update($table, ['replaced_at' => $now, 'updated_at' => $now], ['id' => $invoiceId, 'replaced_at' => null]));
                        $this->proposals->appendAudit((int) $source['version_id'], 'invoice.replacement_repaired', ['invoice_id' => $invoiceId, 'replacement_id' => (int) $existing['id'], 'actor_id' => $actorId], true);
                    }
                    $replacementId = (int) $existing['id'];
                } else {
                    $sourceSnapshot = $this->hydrate($source);
                    $this->assertSnapshotIntegrity($source);
                    $publicId = bin2hex(random_bytes(16));
                    $snapshotHash = InvoiceSnapshot::hash($publicId, (int) $source['acceptance_id'], (int) $source['version_id'], (string) $source['currency'], (int) $source['total_minor'], $sourceSnapshot['customer_profile'], $sourceSnapshot['supplier_profile'], $sourceSnapshot['items']);
                    $this->mustWrite($wpdb->insert($table, [
                        'acceptance_id' => (int) $source['acceptance_id'], 'version_id' => (int) $source['version_id'],
                        'public_id' => $publicId, 'invoice_number' => null, 'currency' => (string) $source['currency'],
                        'customer_payload' => $source['customer_payload'], 'supplier_payload' => $source['supplier_payload'], 'items_payload' => $source['items_payload'],
                        'subtotal_minor' => (int) $source['subtotal_minor'], 'total_minor' => (int) $source['total_minor'],
                        'status' => 'draft', 'vies_status' => 'not_requested', 'legacy_backfill_required' => 0, 'snapshot_hash' => $snapshotHash,
                        'replacement_for_id' => $invoiceId, 'created_at' => $now, 'updated_at' => $now,
                    ]));
                    $replacementId = (int) $wpdb->insert_id;
                    $this->mustWrite($wpdb->update($table, ['replaced_at' => $now, 'updated_at' => $now], ['id' => $invoiceId, 'replaced_at' => null]));
                    $this->proposals->appendAudit((int) $source['version_id'], 'invoice.replacement_created', ['invoice_id' => $invoiceId, 'replacement_id' => $replacementId, 'actor_id' => $actorId, 'snapshot_hash' => $snapshotHash], true);
                }
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($replacementId);
            });
        });
    }

    public function updateVies(int $invoiceId, array $result): array
    {
        global $wpdb;
        $allowed = ['not_requested', 'pending', 'valid', 'invalid', 'unavailable'];
        $status = (string) ($result['status'] ?? 'unavailable');
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid VIES status.');
        }
        $updated = $wpdb->update($this->table('e7_proposal_invoices'), [
            'vies_status' => $status,
            'vies_checked_at' => $result['checked_at'] ?? current_time('mysql', true),
            'vies_evidence' => wp_json_encode($result['evidence'] ?? []),
            'updated_at' => current_time('mysql', true),
        ], ['id' => $invoiceId]);
        if ($updated !== 1 && $updated !== 0) {
            throw new \RuntimeException('VIES result could not be persisted.');
        }
        return $this->requireInvoice($invoiceId);
    }

    public function markIssued(int $invoiceId, array $artifact): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-record-' . $invoiceId, function () use ($wpdb, $invoiceId): array {
            $candidate = $this->requireInvoice($invoiceId);
            return $this->proposals->withAuditLock((int) $candidate['version_id'], function () use ($wpdb, $invoiceId): array {
                $table = $this->table('e7_proposal_invoices');
                $wpdb->query('START TRANSACTION');
                try {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $invoiceId), ARRAY_A);
                    if (! is_array($row) || $row['status'] !== 'processing') {
                        throw new \DomainException('Only a processing invoice can be issued.');
                    }
                    $this->assertSnapshotIntegrity($row);
                    if (! is_string($row['artifact_key'] ?? null) || $row['artifact_key'] === '' || ! preg_match('/^[a-f0-9]{64}$/', (string) ($row['artifact_hash'] ?? '')) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($row['signature_payload_hash'] ?? '')) || empty($row['issued_at'])) {
                        throw new \DomainException('Invoice artifact evidence must be persisted before issue.');
                    }
                    $now = current_time('mysql', true);
                    $this->mustWrite($wpdb->update($table, [
                        'status' => 'issued',
                        'last_error' => null,
                        'updated_at' => $now,
                    ], ['id' => $invoiceId, 'status' => 'processing']));
                    $sourceId = isset($row['replacement_for_id']) ? (int) $row['replacement_for_id'] : 0;
                    if ($sourceId > 0) {
                        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $sourceId), ARRAY_A);
                        if (! is_array($source) || (int) $source['version_id'] !== (int) $row['version_id'] || ! in_array($source['status'], ['issued', 'cancelled'], true)) {
                            throw new \DomainException('Replacement invoice source is invalid.');
                        }
                        if ($source['status'] === 'issued') {
                            $this->mustWrite($wpdb->update($table, [
                                'status' => 'cancelled',
                                'cancelled_at' => $now,
                                'replaced_at' => $now,
                                'updated_at' => $now,
                            ], ['id' => $sourceId, 'status' => 'issued']));
                        }
                        $this->proposals->appendAudit((int) $row['version_id'], 'invoice.replaced', [
                            'invoice_id' => $sourceId,
                            'replacement_id' => $invoiceId,
                            'replacement_number' => (string) $row['invoice_number'],
                        ], true);
                    }
                    $this->proposals->appendAudit((int) $row['version_id'], 'invoice.issued', [
                        'invoice_id' => $invoiceId,
                        'invoice_number' => (string) $row['invoice_number'],
                        'artifact_hash' => (string) $row['artifact_hash'],
                        'signature_payload_hash' => (string) $row['signature_payload_hash'],
                    ], true);
                    $wpdb->query('COMMIT');
                } catch (\Throwable $error) {
                    $wpdb->query('ROLLBACK');
                    throw $error;
                }
                return $this->requireInvoice($invoiceId);
            });
        });
    }

    public function appendAudit(int $versionId, string $type, array $payload): void
    {
        $this->proposals->appendAudit($versionId, $type, $payload);
    }

    private function reserveNumber(int $year): string
    {
        global $wpdb;
        $table = $this->table('e7_proposal_invoice_sequences');
        $now = current_time('mysql', true);
        if ($wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (sequence_scope,sequence_year,current_value,created_at,updated_at) VALUES ('commercial',%d,0,%s,%s)",
            $year,
            $now,
            $now,
        )) === false) {
            throw new \RuntimeException('Invoice sequence could not be initialized.');
        }
        $current = $wpdb->get_var($wpdb->prepare("SELECT current_value FROM $table WHERE sequence_scope='commercial' AND sequence_year=%d FOR UPDATE", $year));
        $sequence = (int) $current === 0 ? InvoiceNumber::initialSequence() : InvoiceNumber::next((int) $current);
        $this->mustWrite($wpdb->update($table, ['current_value' => $sequence, 'updated_at' => $now], ['sequence_scope' => 'commercial', 'sequence_year' => $year]));
        return InvoiceNumber::format($year, $sequence);
    }

    /** @param array<string, mixed> $invoice */
    private function insertFinalizeJob(array $invoice, string $now): void
    {
        global $wpdb;
        $this->mustWrite($wpdb->insert($this->table('e7_proposal_jobs'), [
            'version_id' => (int) $invoice['version_id'], 'job_type' => 'finalize_invoice',
            'idempotency_key' => $this->jobKey($invoice), 'status' => 'pending', 'attempts' => 0,
            'next_run_at' => $now, 'payload' => wp_json_encode(['invoice_id' => (int) $invoice['id'], 'public_id' => $invoice['public_id']]),
            'created_at' => $now, 'updated_at' => $now,
        ]));
    }

    /** @param array<string, mixed> $invoice */
    private function resetFinalizeJob(array $invoice, string $now): void
    {
        global $wpdb;
        $jobs = $this->table('e7_proposal_jobs');
        $key = $this->jobKey($invoice);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $jobs WHERE idempotency_key=%s FOR UPDATE", $key), ARRAY_A);
        if (! is_array($existing)) {
            $this->insertFinalizeJob($invoice, $now);
            return;
        }
        $this->mustWrite($wpdb->update($jobs, ['status' => 'pending', 'attempts' => 0, 'next_run_at' => $now, 'locked_at' => null, 'last_error' => null, 'updated_at' => $now], ['id' => (int) $existing['id']]));
    }

    /** @param array<string, mixed> $invoice */
    private function jobKey(array $invoice): string
    {
        return hash('sha256', 'finalize_invoice:' . (string) $invoice['public_id']);
    }

    /** @param array<string, mixed> $row */
    private function assertSnapshotIntegrity(array $row): void
    {
        if ((int) ($row['legacy_backfill_required'] ?? 0) === 1) {
            throw new \DomainException('Legacy invoice backfill is required.');
        }
        $invoice = $this->hydrate($row);
        $actual = InvoiceSnapshot::hash((string) $row['public_id'], (int) $row['acceptance_id'], (int) $row['version_id'], (string) $row['currency'], (int) $row['total_minor'], $invoice['customer_profile'], $invoice['supplier_profile'], $invoice['items']);
        if (! is_string($row['snapshot_hash'] ?? null) || ! hash_equals($row['snapshot_hash'], $actual)) {
            throw new \DomainException('Invoice snapshot integrity check failed.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['acceptance_id'] = (int) $row['acceptance_id'];
        $row['version_id'] = (int) $row['version_id'];
        $row['subtotal_minor'] = (int) $row['subtotal_minor'];
        $row['total_minor'] = (int) $row['total_minor'];
        $row['replacement_for_id'] = isset($row['replacement_for_id']) ? (int) $row['replacement_for_id'] : null;
        $row['legacy_backfill_required'] = (int) ($row['legacy_backfill_required'] ?? 0) === 1;
        $row['customer_profile'] = $this->openArray((string) $row['customer_payload']);
        $row['supplier_profile'] = $this->openArray((string) $row['supplier_payload']);
        $row['items'] = $this->openArray((string) $row['items_payload']);
        $row['vies_evidence'] = is_string($row['vies_evidence'] ?? null) ? json_decode($row['vies_evidence'], true) : [];
        return $row;
    }

    /** @return array<string, mixed> */
    private function requireInvoice(int $invoiceId): array
    {
        $invoice = $this->get($invoiceId);
        if (! is_array($invoice)) {
            throw new \RuntimeException('Invoice was not found after persistence.');
        }
        return $invoice;
    }

    /** @return array<string, mixed>|null */
    private function openNullableProfile(mixed $payload): ?array
    {
        return is_string($payload) && $payload !== '' ? $this->openArray($payload) : null;
    }

    /** @return array<string, mixed> */
    private function openArray(string $payload): array
    {
        $decoded = json_decode($this->crypto->open($payload), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function seal(array $payload): string
    {
        return $this->crypto->seal(CanonicalPayload::encode($payload));
    }

    private function withLock(string $name, callable $operation): mixed
    {
        global $wpdb;
        $lock = substr($name . '-' . get_current_blog_id(), 0, 64);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            throw new \RuntimeException('Could not acquire invoice lock.');
        }
        try {
            return $operation();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    private function mustWrite(int|false $result): void
    {
        if ($result === false || $result < 1) {
            throw new \RuntimeException('Invoice database write failed.');
        }
    }
}
