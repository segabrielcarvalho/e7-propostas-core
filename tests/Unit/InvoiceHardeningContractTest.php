<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InvoiceHardeningContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_issue_and_retry_persist_transition_and_idempotent_job_in_one_transaction(): void
    {
        $repository = $this->read('src/WordPress/InvoiceRepository.php');
        self::assertStringContainsString('issueAndEnqueue', $repository);
        self::assertStringContainsString('retryAndEnqueue', $repository);
        self::assertStringContainsString("'job_type' => 'finalize_invoice'", $repository);
        self::assertStringContainsString("'idempotency_key' =>", $repository);
        self::assertStringContainsString("\$wpdb->query('START TRANSACTION')", $repository);
    }

    public function test_cancelled_root_still_prevents_a_second_root_and_creation_is_locked(): void
    {
        $repository = $this->read('src/WordPress/InvoiceRepository.php');
        $currentRoot = substr($repository, (int) strpos($repository, 'public function currentRoot'), 700);
        self::assertStringNotContainsString("status <> 'cancelled'", $currentRoot);
        self::assertStringContainsString('e7-invoice-acceptance-', $repository);
    }

    public function test_schema_tracks_legacy_integrity_hash_and_job_idempotency(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');
        foreach (['legacy_backfill_required tinyint(1)', 'snapshot_hash char(64)', 'idempotency_key char(64)', 'UNIQUE KEY idempotency_key'] as $contract) {
            self::assertStringContainsString($contract, $installer);
        }
    }

    public function test_acceptance_worker_cannot_claim_invoice_jobs(): void
    {
        $processor = $this->read('src/Infrastructure/ArtifactProcessor.php');
        self::assertStringContainsString("job_type='finalize_acceptance'", $processor);
    }

    public function test_replacement_persistence_and_audit_are_committed_together(): void
    {
        $repository = $this->read('src/WordPress/InvoiceRepository.php');
        $start = (int) strpos($repository, 'public function createReplacement');
        $replacement = substr($repository, $start, 6500);
        self::assertStringContainsString("'invoice.replacement_created'", $replacement);
        self::assertLessThan(strpos($replacement, "query('COMMIT')"), strpos($replacement, "'invoice.replacement_created'"));
        self::assertStringContainsString('invoice.replacement_repaired', $replacement);
        self::assertStringContainsString('withAuditLock', $replacement);
        self::assertStringContainsString('true)', $replacement);
    }

    public function test_legacy_backfill_persistence_marker_and_audit_are_committed_together(): void
    {
        $repository = $this->read('src/WordPress/InvoiceRepository.php');
        $start = (int) strpos($repository, 'public function backfillLegacy');
        $backfill = substr($repository, $start, 5000);

        self::assertStringContainsString('withAuditLock', $backfill);
        self::assertStringContainsString("'invoice.legacy_backfill_confirmed'", $backfill);
        self::assertStringContainsString('true)', $backfill);
        self::assertStringContainsString("query('ROLLBACK')", $backfill);
        self::assertLessThan(strpos($backfill, "query('COMMIT')"), strpos($backfill, "'invoice.legacy_backfill_confirmed'"));
    }

    public function test_mark_issued_revalidates_snapshot_under_transaction_and_record_lock(): void
    {
        $repository = $this->read('src/WordPress/InvoiceRepository.php');
        $start = (int) strpos($repository, 'public function markIssued');
        $markIssued = substr($repository, $start, 4000);

        self::assertStringContainsString('e7-invoice-record-', $markIssued);
        self::assertStringContainsString("query('START TRANSACTION')", $markIssued);
        self::assertStringContainsString('FOR UPDATE', $markIssued);
        self::assertStringContainsString('assertSnapshotIntegrity', $markIssued);
        self::assertLessThan(strpos($markIssued, "'status' => 'issued'"), strpos($markIssued, 'assertSnapshotIntegrity'));
    }

    public function test_migration_checks_record_and_sequence_writes(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');
        self::assertStringContainsString('Invoice migration record update failed.', $installer);
        self::assertStringContainsString('Invoice sequence migration failed.', $installer);
        self::assertStringContainsString('=== false', $installer);
    }

    public function test_migration_backfills_invoice_job_keys_and_supersedes_legacy_duplicates(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');
        self::assertStringContainsString('migrateInvoiceJobKeys', $installer);
        self::assertStringContainsString("'superseded'", $installer);
        self::assertStringContainsString('Invoice job idempotency migration failed.', $installer);
        self::assertStringContainsString("'finalize_invoice:'", $installer);
    }

    public function test_admin_renders_error_notice_and_explicit_legacy_backfill(): void
    {
        $admin = $this->read('src/WordPress/InvoiceAdmin.php');
        self::assertStringContainsString("notice === 'error'", $admin);
        self::assertStringContainsString('notice-error', $admin);
        self::assertStringContainsString("'backfill_legacy'", $admin);
    }

    public function test_admin_only_renders_legacy_backfill_for_drafts(): void
    {
        $admin = $this->read('src/WordPress/InvoiceAdmin.php');
        self::assertStringContainsString("! empty(\$invoice['legacy_backfill_required']) && \$status === 'draft'", $admin);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents);
        return $contents;
    }
}
