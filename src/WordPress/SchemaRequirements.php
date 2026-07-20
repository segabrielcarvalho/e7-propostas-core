<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class SchemaRequirements
{
    /** @param array<string, array<string, mixed>> $schema */
    public static function assertReady(array $schema): void
    {
        self::assertColumns($schema, 'acceptances', ['business_payload']);
        self::assertIndex($schema, 'acceptances', 'version_idempotency', ['version_id', 'idempotency_key'], true);
        if (isset($schema['acceptances']['indexes']['idempotency_key'])) {
            throw new \RuntimeException('Legacy acceptance idempotency index is still present.');
        }

        self::assertColumns($schema, 'invoices', ['id', 'acceptance_id', 'version_id', 'invoice_number', 'currency', 'items_payload', 'subtotal_minor', 'total_minor', 'status', 'issued_at', 'sent_at', 'paid_at', 'voided_at', 'replaced_at', 'due_at', 'artifact_key', 'artifact_hash', 'kms_signature', 'provider_message_id', 'replacement_for_id', 'created_at', 'updated_at']);
        self::assertIndex($schema, 'invoices', 'acceptance_id', ['acceptance_id'], false);
        self::assertIndex($schema, 'invoices', 'invoice_number', ['invoice_number'], true);
        self::assertIndex($schema, 'invoices', 'replacement_for_id', ['replacement_for_id'], true);
        self::assertIndex($schema, 'invoices', 'version_status', ['version_id', 'status'], false);

        self::assertColumns($schema, 'sequences', ['id', 'sequence_scope', 'sequence_year', 'current_value', 'created_at', 'updated_at']);
        self::assertIndex($schema, 'sequences', 'sequence_scope_year', ['sequence_scope', 'sequence_year'], true);
    }

    /** @param array<string, array<string, mixed>> $schema @param list<string> $columns */
    public static function hasIndex(array $schema, string $table, string $name, array $columns, bool $unique): bool
    {
        $index = $schema[$table]['indexes'][$name] ?? null;
        return is_array($index)
            && ($index['unique'] ?? null) === $unique
            && array_values(is_array($index['columns'] ?? null) ? $index['columns'] : []) === $columns;
    }

    /** @param array<string, array<string, mixed>> $schema @param list<string> $columns */
    private static function assertColumns(array $schema, string $table, array $columns): void
    {
        $actual = is_array($schema[$table]['columns'] ?? null) ? $schema[$table]['columns'] : [];
        foreach ($columns as $column) {
            if (! in_array($column, $actual, true)) {
                throw new \RuntimeException(sprintf('Required schema column is missing: %s.%s', $table, $column));
            }
        }
    }

    /** @param array<string, array<string, mixed>> $schema @param list<string> $columns */
    private static function assertIndex(array $schema, string $table, string $name, array $columns, bool $unique): void
    {
        if (! self::hasIndex($schema, $table, $name, $columns, $unique)) {
            throw new \RuntimeException(sprintf('Required schema index is invalid: %s.%s', $table, $name));
        }
    }
}
