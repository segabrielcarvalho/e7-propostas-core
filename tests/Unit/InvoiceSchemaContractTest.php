<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InvoiceSchemaContractTest extends TestCase
{
    public function test_complete_invoice_schema_has_nullable_number_encrypted_snapshots_and_lifecycle_evidence(): void
    {
        $installer = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Installer.php');
        self::assertIsString($installer);

        foreach ([
            'public_id char(32) NULL',
            'customer_payload longtext NULL',
            'supplier_payload longtext NULL',
            'invoice_number varchar(64) NULL',
            'items_payload longtext NULL',
            "status varchar(20) NOT NULL DEFAULT 'draft'",
            "vies_status varchar(20) NOT NULL DEFAULT 'not_requested'",
            'vies_checked_at datetime NULL',
            'vies_evidence longtext NULL',
            'cancelled_at datetime NULL',
            'replaced_at datetime NULL',
            'replacement_for_id bigint unsigned NULL',
            'UNIQUE KEY public_id (public_id)',
            'UNIQUE KEY invoice_number (invoice_number)',
        ] as $contract) {
            self::assertStringContainsString($contract, $installer);
        }
    }

    public function test_migration_backfills_public_ids_and_seals_legacy_payloads_before_readiness(): void
    {
        $installer = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Installer.php');
        self::assertIsString($installer);
        self::assertStringContainsString('migrateInvoiceRecords', $installer);
        self::assertStringContainsString("bin2hex(random_bytes(16))", $installer);
        self::assertStringContainsString('$crypto->seal', $installer);
        self::assertStringContainsString('MODIFY `invoice_number` varchar(64) NULL', $installer);
        self::assertStringContainsString('assertSchemaInstalled', $installer);
    }
}
