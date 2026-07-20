<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\CanonicalPayload;
use E7Propostas\Domain\InvoiceNumber;
use E7Propostas\Infrastructure\Crypto;

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
            "SELECT * FROM $table WHERE acceptance_id=%d AND replacement_for_id IS NULL AND status <> 'cancelled' ORDER BY id DESC LIMIT 1",
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
            $this->mustWrite($wpdb->insert($this->table('e7_proposal_invoices'), [
                'acceptance_id' => $acceptanceId,
                'version_id' => (int) $snapshot['version_id'],
                'public_id' => bin2hex(random_bytes(16)),
                'invoice_number' => null,
                'currency' => (string) $snapshot['currency'],
                'customer_payload' => $this->seal((array) $snapshot['customer_profile']),
                'supplier_payload' => $this->seal((array) $snapshot['supplier_profile']),
                'items_payload' => $this->seal((array) $snapshot['items']),
                'subtotal_minor' => (int) $snapshot['total_minor'],
                'total_minor' => (int) $snapshot['total_minor'],
                'status' => 'draft',
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
        $updated = $wpdb->update($this->table('e7_proposal_invoices'), [
            'customer_payload' => $this->seal($profile),
            'vies_status' => 'not_requested',
            'vies_checked_at' => null,
            'vies_evidence' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $invoiceId, 'status' => 'draft']);
        if ($updated !== 1) {
            throw new \DomainException('Draft customer data could not be updated.');
        }
        return $this->requireInvoice($invoiceId);
    }

    public function beginIssue(int $invoiceId): array
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
                $wpdb->query('COMMIT');
            } catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            return $this->requireInvoice($invoiceId);
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

    public function beginRetry(int $invoiceId): array
    {
        global $wpdb;
        $this->mustWrite($wpdb->update($this->table('e7_proposal_invoices'), [
            'status' => 'processing',
            'last_error' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => $invoiceId, 'status' => 'failed']));
        return $this->requireInvoice($invoiceId);
    }

    public function cancel(int $invoiceId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $this->mustWrite($wpdb->update($this->table('e7_proposal_invoices'), [
            'status' => 'cancelled',
            'cancelled_at' => $now,
            'updated_at' => $now,
        ], ['id' => $invoiceId, 'status' => 'issued']));
        return $this->requireInvoice($invoiceId);
    }

    public function createReplacement(int $invoiceId): array
    {
        global $wpdb;
        return $this->withLock('e7-invoice-replacement-' . $invoiceId, function () use ($wpdb, $invoiceId): array {
            $table = $this->table('e7_proposal_invoices');
            $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $invoiceId), ARRAY_A);
            if (! is_array($source) || ! in_array($source['status'], ['issued', 'cancelled'], true)) {
                throw new \DomainException('Invoice cannot be replaced.');
            }
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE replacement_for_id=%d", $invoiceId), ARRAY_A);
            if (is_array($existing)) {
                return $this->hydrate($existing);
            }
            $now = current_time('mysql', true);
            $this->mustWrite($wpdb->insert($table, [
                'acceptance_id' => (int) $source['acceptance_id'],
                'version_id' => (int) $source['version_id'],
                'public_id' => bin2hex(random_bytes(16)),
                'invoice_number' => null,
                'currency' => (string) $source['currency'],
                'customer_payload' => $source['customer_payload'],
                'supplier_payload' => $source['supplier_payload'],
                'items_payload' => $source['items_payload'],
                'subtotal_minor' => (int) $source['subtotal_minor'],
                'total_minor' => (int) $source['total_minor'],
                'status' => 'draft',
                'vies_status' => 'not_requested',
                'replacement_for_id' => $invoiceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $replacementId = (int) $wpdb->insert_id;
            $wpdb->update($table, ['replaced_at' => $now, 'updated_at' => $now], ['id' => $invoiceId]);
            return $this->requireInvoice($replacementId);
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

    public function enqueueFinalization(int $invoiceId): void
    {
        global $wpdb;
        $invoice = $this->requireInvoice($invoiceId);
        $now = current_time('mysql', true);
        $this->mustWrite($wpdb->insert($this->table('e7_proposal_jobs'), [
            'version_id' => (int) $invoice['version_id'],
            'job_type' => 'finalize_invoice',
            'status' => 'pending',
            'attempts' => 0,
            'next_run_at' => $now,
            'payload' => wp_json_encode(['invoice_id' => $invoiceId, 'public_id' => $invoice['public_id']]),
            'created_at' => $now,
            'updated_at' => $now,
        ]));
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
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (sequence_scope,sequence_year,current_value,created_at,updated_at) VALUES ('commercial',%d,0,%s,%s)",
            $year,
            $now,
            $now,
        ));
        $current = $wpdb->get_var($wpdb->prepare("SELECT current_value FROM $table WHERE sequence_scope='commercial' AND sequence_year=%d FOR UPDATE", $year));
        $sequence = (int) $current === 0 ? InvoiceNumber::initialSequence() : InvoiceNumber::next((int) $current);
        $this->mustWrite($wpdb->update($table, ['current_value' => $sequence, 'updated_at' => $now], ['sequence_scope' => 'commercial', 'sequence_year' => $year]));
        return InvoiceNumber::format($year, $sequence);
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
