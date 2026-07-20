<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\WordPress\SchemaRequirements;
use PHPUnit\Framework\TestCase;

final class SchemaRequirementsTest extends TestCase
{
    public function test_complete_schema_is_ready_and_acceptance_index_is_not_unique(): void
    {
        self::assertTrue(class_exists(SchemaRequirements::class), 'SchemaRequirements must exist.');
        $schema = $this->completeSchema();
        SchemaRequirements::assertReady($schema);
        self::assertFalse($schema['invoices']['indexes']['acceptance_id']['unique']);
    }

    public function test_missing_critical_invoice_column_is_rejected(): void
    {
        self::assertTrue(class_exists(SchemaRequirements::class), 'SchemaRequirements must exist.');
        $schema = $this->completeSchema();
        $schema['invoices']['columns'] = array_values(array_diff($schema['invoices']['columns'], ['artifact_hash']));
        $this->expectException(\RuntimeException::class);
        SchemaRequirements::assertReady($schema);
    }

    public function test_unique_acceptance_index_is_rejected_to_allow_replacements(): void
    {
        self::assertTrue(class_exists(SchemaRequirements::class), 'SchemaRequirements must exist.');
        $schema = $this->completeSchema();
        $schema['invoices']['indexes']['acceptance_id']['unique'] = true;
        $this->expectException(\RuntimeException::class);
        SchemaRequirements::assertReady($schema);
    }

    public function test_missing_composite_idempotency_index_is_rejected(): void
    {
        self::assertTrue(class_exists(SchemaRequirements::class), 'SchemaRequirements must exist.');
        $schema = $this->completeSchema();
        unset($schema['acceptances']['indexes']['version_idempotency']);
        $this->expectException(\RuntimeException::class);
        SchemaRequirements::assertReady($schema);
    }

    public function test_legacy_idempotency_index_is_rejected_even_with_the_composite_present(): void
    {
        self::assertTrue(class_exists(SchemaRequirements::class), 'SchemaRequirements must exist.');
        $schema = $this->completeSchema();
        $schema['acceptances']['indexes']['idempotency_key'] = ['unique' => true, 'columns' => ['idempotency_key']];
        $this->expectException(\RuntimeException::class);
        SchemaRequirements::assertReady($schema);
    }

    public function test_installer_checks_the_legacy_drop_and_reinspects_before_updating_the_version(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Installer.php');
        self::assertIsString($source);
        $start = strpos($source, 'private static function migrateAcceptanceIdempotencyIndex');
        $end = strpos($source, 'private static function migrateInvoiceAcceptanceIndex');
        self::assertIsInt($start);
        self::assertIsInt($end);
        $migration = substr($source, $start, $end - $start);
        self::assertStringContainsString('DROP INDEX `idempotency_key`', $migration);
        self::assertStringContainsString('=== false', $migration);
        self::assertGreaterThanOrEqual(2, substr_count($migration, 'self::inspectSchema()'));
        self::assertLessThan(
            strpos($source, "update_option('e7_propostas_schema_version'"),
            strpos($source, 'self::assertSchemaInstalled()'),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function completeSchema(): array
    {
        return [
            'acceptances' => [
                'columns' => ['business_payload'],
                'indexes' => ['version_idempotency' => ['unique' => true, 'columns' => ['version_id', 'idempotency_key']]],
            ],
            'invoices' => [
                'columns' => ['id', 'acceptance_id', 'version_id', 'invoice_number', 'currency', 'items_payload', 'subtotal_minor', 'total_minor', 'status', 'issued_at', 'sent_at', 'paid_at', 'voided_at', 'replaced_at', 'due_at', 'artifact_key', 'artifact_hash', 'kms_signature', 'provider_message_id', 'replacement_for_id', 'created_at', 'updated_at'],
                'indexes' => [
                    'acceptance_id' => ['unique' => false, 'columns' => ['acceptance_id']],
                    'invoice_number' => ['unique' => true, 'columns' => ['invoice_number']],
                    'replacement_for_id' => ['unique' => true, 'columns' => ['replacement_for_id']],
                    'version_status' => ['unique' => false, 'columns' => ['version_id', 'status']],
                ],
            ],
            'sequences' => [
                'columns' => ['id', 'sequence_scope', 'sequence_year', 'current_value', 'created_at', 'updated_at'],
                'indexes' => ['sequence_scope_year' => ['unique' => true, 'columns' => ['sequence_scope', 'sequence_year']]],
            ],
        ];
    }
}
