<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class Installer
{
    public const SCHEMA_VERSION = '1.5.0';

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            deactivate_plugins(plugin_basename(dirname(__DIR__, 2) . '/e7-propostas-core.php'));
            wp_die(esc_html__('Ative o E7 Propostas Core somente no subsite de propostas.', 'e7-propostas'));
        }

        self::installTables();
        self::assertSchemaInstalled();
        update_option('e7_propostas_core_enabled', '1', false);
        update_option('e7_propostas_schema_version', self::SCHEMA_VERSION, false);

        $role = get_role('administrator');
        if ($role) {
            foreach (ProposalPostType::capabilities() as $capability) {
                $role->add_cap($capability);
            }
        }

        PublicRoutes::registerRewrites();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function ensureSchema(): void
    {
        if (get_option('e7_propostas_schema_version') === self::SCHEMA_VERSION) {
            return;
        }
        self::installTables();
        self::migrateAcceptanceIdempotencyIndex();
        self::assertSchemaInstalled();
        update_option('e7_propostas_schema_version', self::SCHEMA_VERSION, false);
        self::scheduleRewriteFlush();
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
            invoice_number varchar(64) NOT NULL,
            currency char(3) NOT NULL DEFAULT 'EUR',
            items_payload longtext NOT NULL,
            subtotal_minor bigint unsigned NOT NULL,
            total_minor bigint unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            issued_at datetime NULL,
            sent_at datetime NULL,
            paid_at datetime NULL,
            voided_at datetime NULL,
            replaced_at datetime NULL,
            due_at datetime NULL,
            artifact_key text NULL,
            artifact_hash char(64) NULL,
            kms_signature longtext NULL,
            provider_message_id varchar(255) NULL,
            replacement_for_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY acceptance_id (acceptance_id),
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
        $table = $wpdb->prefix . 'e7_proposal_acceptances';
        $legacy = $wpdb->get_var($wpdb->prepare("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idempotency_key' LIMIT 1", $table));
        if ($legacy === 'idempotency_key') {
            $wpdb->query("ALTER TABLE `$table` DROP INDEX `idempotency_key`");
        }
    }

    private static function assertSchemaInstalled(): void
    {
        global $wpdb;
        foreach (['e7_proposal_invoices', 'e7_proposal_invoice_sequences'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new \RuntimeException('Required proposal schema table was not installed: ' . $suffix);
            }
        }

        $acceptances = str_replace('`', '``', $wpdb->prefix . 'e7_proposal_acceptances');
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$acceptances` LIKE %s", 'business_payload'));
        if ($column !== 'business_payload') {
            throw new \RuntimeException('Required acceptance business_payload column was not installed.');
        }
    }
}
