<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\CanonicalPayload;
use E7Propostas\Domain\InvoiceSnapshot;
use E7Propostas\Domain\SupplierProfile;
use E7Propostas\Infrastructure\Crypto;

final class Installer
{
    public const SCHEMA_VERSION = '1.7.1';

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            deactivate_plugins(plugin_basename(dirname(__DIR__, 2) . '/e7-propostas-core.php'));
            wp_die(esc_html__('Ative o E7 Propostas Core somente no subsite de propostas.', 'e7-propostas'));
        }

        self::installTables();
        self::migrateInvoiceAcceptanceIndex();
        self::migrateAcceptanceIdempotencyIndex();
        self::migrateInvoiceRecords();
        self::migrateInvoiceJobKeys();
        self::assertSchemaInstalled();
        update_option('e7_propostas_core_enabled', '1', false);
        update_option('e7_propostas_schema_version', self::SCHEMA_VERSION, false);

        self::ensureCapabilities();

        PublicRoutes::registerRewrites();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function ensureSchema(): void
    {
        self::ensureCapabilities();
        if (get_option('e7_propostas_schema_version') === self::SCHEMA_VERSION) {
            return;
        }
        self::installTables();
        self::migrateInvoiceAcceptanceIndex();
        self::migrateAcceptanceIdempotencyIndex();
        self::migrateInvoiceRecords();
        self::migrateInvoiceJobKeys();
        self::assertSchemaInstalled();
        update_option('e7_propostas_schema_version', self::SCHEMA_VERSION, false);
        self::scheduleRewriteFlush();
    }

    private static function ensureCapabilities(): void
    {
        $role = get_role('administrator');
        if ($role) {
            foreach (ProposalPostType::capabilities() as $capability) {
                $role->add_cap($capability);
            }
        }
    }

    private static function scheduleRewriteFlush(): void
    {
        add_action('init', static function (): void {
            PublicRoutes::registerRewrites();
            flush_rewrite_rules(false);
        }, PHP_INT_MAX);
    }

    private static function installTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $schemas = [];
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_settings (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint unsigned NOT NULL,
            share_code char(8) NULL,
            client_payload longtext NOT NULL,
            password_hash varchar(255) NOT NULL DEFAULT '',
            locale varchar(10) NOT NULL DEFAULT 'pt_BR',
            currency varchar(3) NOT NULL DEFAULT 'BRL',
            expires_at datetime NULL,
            otp_policy varchar(16) NOT NULL DEFAULT 'email',
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            UNIQUE KEY share_code (share_code)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_versions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint unsigned NOT NULL,
            version_no int unsigned NOT NULL,
            snapshot_html longtext NOT NULL,
            snapshot_json longtext NOT NULL,
            document_hash char(64) NOT NULL,
            token_hash char(64) NOT NULL,
            token_ciphertext text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            accepted_at datetime NULL,
            artifact_key text NULL,
            artifact_hash char(64) NULL,
            kms_signature longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY post_version (post_id,version_no),
            KEY status (status)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_sessions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            version_id bigint unsigned NOT NULL,
            session_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            authorized_at datetime NOT NULL,
            otp_verified_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_hash (session_hash),
            KEY version_id (version_id)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_otps (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint unsigned NOT NULL,
            version_id bigint unsigned NOT NULL,
            channel varchar(12) NOT NULL,
            destination varchar(254) NOT NULL DEFAULT '',
            code_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            consumed_at datetime NULL,
            provider_message_id varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY session_version (session_id,version_id),
            KEY created_at (created_at)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_acceptances (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            version_id bigint unsigned NOT NULL,
            public_id char(32) NOT NULL,
            idempotency_key char(64) NOT NULL,
            signer_name varchar(190) NOT NULL,
            signer_role varchar(190) NOT NULL DEFAULT '',
            signer_company varchar(190) NOT NULL DEFAULT '',
            signer_email varchar(254) NOT NULL DEFAULT '',
            signer_phone varchar(32) NOT NULL DEFAULT '',
            business_payload longtext NULL,
            consent_text text NOT NULL,
            accepted_at datetime NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent text NOT NULL,
            audit_hash char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY version_id (version_id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY version_idempotency (version_id,idempotency_key)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_invoices (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            acceptance_id bigint unsigned NOT NULL,
            version_id bigint unsigned NOT NULL,
            public_id char(32) NULL,
            invoice_number varchar(64) NULL,
            currency char(3) NOT NULL DEFAULT 'EUR',
            customer_payload longtext NULL,
            supplier_payload longtext NULL,
            items_payload longtext NULL,
            subtotal_minor bigint unsigned NOT NULL,
            total_minor bigint unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            legacy_backfill_required tinyint(1) NOT NULL DEFAULT 0,
            snapshot_hash char(64) NULL,
            vies_status varchar(20) NOT NULL DEFAULT 'not_requested',
            vies_checked_at datetime NULL,
            vies_evidence longtext NULL,
            issued_at datetime NULL,
            cancelled_at datetime NULL,
            sent_at datetime NULL,
            paid_at datetime NULL,
            voided_at datetime NULL,
            replaced_at datetime NULL,
            due_at datetime NULL,
            artifact_key text NULL,
            artifact_hash char(64) NULL,
            kms_signature longtext NULL,
            provider_message_id varchar(255) NULL,
            last_error text NULL,
            replacement_for_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY acceptance_id (acceptance_id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY invoice_number (invoice_number),
            UNIQUE KEY replacement_for_id (replacement_for_id),
            KEY version_status (version_id,status)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_invoice_sequences (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            sequence_scope varchar(32) NOT NULL,
            sequence_year smallint unsigned NOT NULL,
            current_value bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sequence_scope_year (sequence_scope,sequence_year)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_audit_events (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            version_id bigint unsigned NOT NULL,
            event_type varchar(80) NOT NULL,
            payload longtext NOT NULL,
            previous_hash char(64) NULL,
            event_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY version_id (version_id)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            version_id bigint unsigned NOT NULL,
            job_type varchar(80) NOT NULL,
            idempotency_key char(64) NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            next_run_at datetime NOT NULL,
            locked_at datetime NULL,
            provider_message_id varchar(255) NULL,
            payload longtext NOT NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY runnable (status,next_run_at)
        ) $charset;";
        $schemas[] = "CREATE TABLE {$prefix}e7_proposal_rate_limits (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            scope_hash char(64) NOT NULL,
            window_started_at datetime NOT NULL,
            attempt_count int unsigned NOT NULL DEFAULT 0,
            blocked_until datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_hash (scope_hash)
        ) $charset;";

        foreach ($schemas as $schema) {
            dbDelta($schema);
        }
    }

    private static function migrateAcceptanceIdempotencyIndex(): void
    {
        global $wpdb;
        $schema = self::inspectSchema();
        if (! SchemaRequirements::hasIndex($schema, 'acceptances', 'version_idempotency', ['version_id', 'idempotency_key'], true)) {
            throw new \RuntimeException('Composite acceptance idempotency index is not ready.');
        }
        $table = str_replace('`', '``', $wpdb->prefix . 'e7_proposal_acceptances');
        if (isset($schema['acceptances']['indexes']['idempotency_key'])) {
            if ($wpdb->query("ALTER TABLE `$table` DROP INDEX `idempotency_key`") === false) {
                throw new \RuntimeException('Could not remove the legacy acceptance idempotency index.');
            }
        }
        $schema = self::inspectSchema();
        if (isset($schema['acceptances']['indexes']['idempotency_key'])) {
            throw new \RuntimeException('Legacy acceptance idempotency index is still present.');
        }
    }

    private static function migrateInvoiceAcceptanceIndex(): void
    {
        global $wpdb;
        $schema = self::inspectSchema();
        $index = $schema['invoices']['indexes']['acceptance_id'] ?? null;
        if (is_array($index) && ($index['unique'] ?? false) === true) {
            $table = str_replace('`', '``', $wpdb->prefix . 'e7_proposal_invoices');
            if ($wpdb->query("ALTER TABLE `$table` DROP INDEX `acceptance_id`, ADD KEY `acceptance_id` (`acceptance_id`)") === false) {
                throw new \RuntimeException('Could not convert the invoice acceptance index.');
            }
        }
    }

    private static function migrateInvoiceRecords(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'e7_proposal_invoices';
        $acceptances = $wpdb->prefix . 'e7_proposal_acceptances';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            return;
        }
        $secret = defined('AUTH_KEY') ? (string) AUTH_KEY : wp_salt('auth');
        $crypto = new Crypto($secret);
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id ASC", ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = (int) $row['id'];
            $itemsPayload = self::sealLegacyPayload($crypto, $row['items_payload'] ?? null, []);
            $customerPayload = $row['customer_payload'] ?? null;
            if (! is_string($customerPayload) || $customerPayload === '') {
                $customerPayload = $wpdb->get_var($wpdb->prepare("SELECT business_payload FROM $acceptances WHERE id=%d", (int) $row['acceptance_id']));
            }
            $customerPayload = self::sealLegacyPayload($crypto, $customerPayload, []);
            $supplierPayload = self::sealLegacyPayload($crypto, $row['supplier_payload'] ?? null, SupplierProfile::defaults());
            $status = match ((string) ($row['status'] ?? 'draft')) {
                'pending' => 'draft',
                'voided' => 'cancelled',
                'sent', 'paid' => 'issued',
                'draft', 'processing', 'issued', 'cancelled', 'failed' => (string) $row['status'],
                default => 'failed',
            };
            $publicId = is_string($row['public_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $row['public_id'])
                ? $row['public_id']
                : bin2hex(random_bytes(16));
            $invoiceNumber = isset($row['invoice_number']) && trim((string) $row['invoice_number']) !== '' ? trim((string) $row['invoice_number']) : null;
            $customer = self::openSealedPayload($crypto, $customerPayload);
            $supplier = self::openSealedPayload($crypto, $supplierPayload);
            $items = self::openSealedPayload($crypto, $itemsPayload);
            $legacyBackfillRequired = (int) ($row['legacy_backfill_required'] ?? 0) === 1;
            try {
                $snapshotHash = InvoiceSnapshot::hash($publicId, (int) $row['acceptance_id'], (int) $row['version_id'], (string) ($row['currency'] ?? 'EUR'), (int) ($row['total_minor'] ?? 0), $customer, $supplier, $items);
            } catch (\Throwable) {
                $legacyBackfillRequired = true;
                $snapshotHash = CanonicalPayload::hash([
                    'public_id' => $publicId,
                    'acceptance_id' => (int) $row['acceptance_id'],
                    'version_id' => (int) $row['version_id'],
                    'currency' => (string) ($row['currency'] ?? 'EUR'),
                    'total_minor' => (int) ($row['total_minor'] ?? 0),
                    'customer' => $customer,
                    'supplier' => $supplier,
                    'items' => $items,
                ]);
            }
            if ($wpdb->update($table, [
                'public_id' => $publicId,
                'invoice_number' => $invoiceNumber,
                'customer_payload' => $customerPayload,
                'supplier_payload' => $supplierPayload,
                'items_payload' => $itemsPayload,
                'status' => $status,
                'legacy_backfill_required' => $legacyBackfillRequired ? 1 : 0,
                'snapshot_hash' => $snapshotHash,
                'cancelled_at' => $row['cancelled_at'] ?? ($row['voided_at'] ?? null),
                'updated_at' => $row['updated_at'] ?? current_time('mysql', true),
            ], ['id' => $id]) === false) {
                throw new \RuntimeException('Invoice migration record update failed.');
            }
            if (is_string($invoiceNumber) && preg_match('/^E7-([0-9]{4})-([0-9]{4})$/', $invoiceNumber, $match)) {
                self::advanceInvoiceSequence((int) $match[1], (int) $match[2]);
            }
        }
        $escaped = str_replace('`', '``', $table);
        if ($wpdb->query("ALTER TABLE `$escaped` MODIFY `public_id` char(32) NOT NULL, MODIFY `invoice_number` varchar(64) NULL, MODIFY `customer_payload` longtext NOT NULL, MODIFY `supplier_payload` longtext NOT NULL, MODIFY `items_payload` longtext NOT NULL, MODIFY `snapshot_hash` char(64) NOT NULL") === false) {
            throw new \RuntimeException('Could not finalize invoice column constraints.');
        }
    }

    /** @return array<string, mixed> */
    private static function openSealedPayload(Crypto $crypto, string $payload): array
    {
        try {
            $decoded = json_decode($crypto->open($payload), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $fallback */
    private static function sealLegacyPayload(Crypto $crypto, mixed $payload, array $fallback): string
    {
        if (is_string($payload) && $payload !== '') {
            try {
                json_decode($crypto->open($payload), true, 512, JSON_THROW_ON_ERROR);
                return $payload;
            } catch (\Throwable) {
                $decoded = json_decode($payload, true);
                $fallback = is_array($decoded) ? $decoded : ['legacy_raw' => $payload];
            }
        }
        return $crypto->seal(CanonicalPayload::encode($fallback));
    }

    private static function advanceInvoiceSequence(int $year, int $value): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'e7_proposal_invoice_sequences';
        $now = current_time('mysql', true);
        if ($wpdb->query($wpdb->prepare(
            "INSERT INTO $table (sequence_scope,sequence_year,current_value,created_at,updated_at) VALUES ('commercial',%d,%d,%s,%s) ON DUPLICATE KEY UPDATE current_value=GREATEST(current_value,VALUES(current_value)), updated_at=VALUES(updated_at)",
            $year,
            $value,
            $now,
            $now,
        )) === false) {
            throw new \RuntimeException('Invoice sequence migration failed.');
        }
    }

    private static function migrateInvoiceJobKeys(): void
    {
        global $wpdb;
        $jobs = $wpdb->prefix . 'e7_proposal_jobs';
        $invoices = $wpdb->prefix . 'e7_proposal_invoices';
        $rows = $wpdb->get_results("SELECT id, payload FROM $jobs WHERE job_type='finalize_invoice' AND idempotency_key IS NULL ORDER BY id ASC", ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $publicId = is_array($payload) ? (string) ($payload['public_id'] ?? '') : '';
            if (! preg_match('/^[a-f0-9]{32}$/', $publicId) && is_array($payload) && isset($payload['invoice_id'])) {
                $publicId = (string) $wpdb->get_var($wpdb->prepare("SELECT public_id FROM $invoices WHERE id=%d", (int) $payload['invoice_id']));
            }
            if (! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
                $result = $wpdb->update($jobs, ['status' => 'failed', 'last_error' => 'Invoice job payload could not be identified during migration.'], ['id' => (int) $row['id']]);
            } else {
                $key = hash('sha256', 'finalize_invoice:' . $publicId);
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $jobs WHERE idempotency_key=%s LIMIT 1", $key));
                $data = is_numeric($existing)
                    ? ['status' => 'superseded', 'last_error' => 'Duplicate invoice job superseded during idempotency migration.']
                    : ['idempotency_key' => $key];
                $result = $wpdb->update($jobs, $data, ['id' => (int) $row['id']]);
            }
            if ($result === false) {
                throw new \RuntimeException('Invoice job idempotency migration failed.');
            }
        }
    }

    private static function assertSchemaInstalled(): void
    {
        SchemaRequirements::assertReady(self::inspectSchema());
    }

    /** @return array<string, array<string, mixed>> */
    private static function inspectSchema(): array
    {
        global $wpdb;
        $schema = [];
        foreach (['acceptances' => 'e7_proposal_acceptances', 'invoices' => 'e7_proposal_invoices', 'sequences' => 'e7_proposal_invoice_sequences', 'jobs' => 'e7_proposal_jobs'] as $name => $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                $schema[$name] = ['columns' => [], 'indexes' => []];
                continue;
            }
            $escaped = str_replace('`', '``', $table);
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `$escaped`", ARRAY_A);
            $rows = $wpdb->get_results("SHOW INDEX FROM `$escaped`", ARRAY_A);
            $indexes = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $key = (string) $row['Key_name'];
                $indexes[$key]['unique'] = (int) $row['Non_unique'] === 0;
                $indexes[$key]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
            }
            foreach ($indexes as &$index) {
                ksort($index['columns'], SORT_NUMERIC);
                $index['columns'] = array_values($index['columns']);
            }
            unset($index);
            $schema[$name] = [
                'columns' => array_values(array_map(static fn (array $column): string => (string) $column['Field'], is_array($columns) ? $columns : [])),
                'column_definitions' => array_column(array_map(static fn (array $column): array => [
                    'name' => (string) $column['Field'],
                    'nullable' => (string) $column['Null'] === 'YES',
                    'type' => strtolower((string) $column['Type']),
                ], is_array($columns) ? $columns : []), null, 'name'),
                'indexes' => $indexes,
            ];
        }
        return $schema;
    }
}
