<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CommercialInvoiceContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_otp_routes_and_acceptance_are_controlled_by_the_backend_flag(): void
    {
        $controller = $this->read('src/WordPress/RestController.php');
        $plugin = $this->read('src/WordPress/Plugin.php');
        $repository = $this->read('src/WordPress/ProposalRepository.php');

        self::assertStringContainsString('FeatureFlags $features', $controller);
        self::assertStringContainsString('$this->features->otpEnabled()', $controller);
        self::assertStringContainsString('new FeatureFlags()', $plugin);
        self::assertStringContainsString("'authentication.verified'", $repository);
        self::assertStringContainsString("'method' => 'password_session'", $repository);
        self::assertStringContainsString("appendAudit(\$versionId, 'otp.verified'", $repository);
    }

    public function test_public_view_exposes_backend_workflow_decisions(): void
    {
        $routes = $this->read('src/WordPress/PublicRoutes.php');

        self::assertStringContainsString("'otp_enabled' =>", $routes);
        self::assertStringContainsString("'irish_invoice_flow' =>", $routes);
        self::assertStringContainsString('AcceptancePolicy::isIrishInvoiceFlow', $routes);
    }

    public function test_business_profile_and_invoice_items_are_immutable_acceptance_inputs(): void
    {
        $controller = $this->read('src/WordPress/RestController.php');
        $repository = $this->read('src/WordPress/ProposalRepository.php');
        $admin = $this->read('src/WordPress/AdminMetaBox.php');

        self::assertStringContainsString("get_param('business_profile')", $controller);
        self::assertStringContainsString('BusinessProfile::normalize', $controller);
        self::assertStringContainsString("'business_payload' =>", $repository);
        self::assertStringContainsString("'invoice_items' =>", $repository);
        self::assertStringContainsString("'invoice_total_minor' =>", $repository);
        self::assertStringContainsString("(\$locale === 'en_IE' && \$currency === 'EUR')", $repository);
        self::assertStringContainsString('e7_proposal[invoice_items]', $admin);
    }

    public function test_schema_1_5_adds_invoice_foundation_without_a_second_job_table(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');

        self::assertStringContainsString("SCHEMA_VERSION = '1.5.1'", $installer);
        self::assertStringContainsString('business_payload longtext NULL', $installer);
        self::assertStringContainsString('e7_proposal_invoices', $installer);
        self::assertStringContainsString('e7_proposal_invoice_sequences', $installer);
        self::assertStringContainsString('replacement_for_id bigint unsigned NULL', $installer);
        self::assertStringContainsString('KEY acceptance_id (acceptance_id)', $installer);
        self::assertStringNotContainsString('UNIQUE KEY acceptance_id', $installer);
        self::assertStringContainsString('UNIQUE KEY invoice_number', $installer);
        self::assertStringContainsString('UNIQUE KEY replacement_for_id', $installer);
        self::assertStringContainsString('UNIQUE KEY sequence_scope_year', $installer);
        self::assertStringContainsString('current_value bigint unsigned', $installer);
        self::assertStringNotContainsString('last_value bigint unsigned', $installer);
        self::assertStringNotContainsString('e7_proposal_invoice_jobs', $installer);
    }

    public function test_schema_version_is_not_advanced_when_db_delta_misses_required_structures(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');
        $ensureSchema = substr($installer, (int) strpos($installer, 'public static function ensureSchema'), 700);

        self::assertStringContainsString('self::assertSchemaInstalled()', $ensureSchema);
        self::assertStringContainsString("update_option('e7_propostas_schema_version'", $ensureSchema);
        self::assertLessThan(
            strpos($ensureSchema, "update_option('e7_propostas_schema_version'"),
            strpos($ensureSchema, 'self::assertSchemaInstalled()'),
        );
    }

    public function test_activation_runs_index_migration_and_legacy_drop_requires_composite_index(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');
        $activate = substr($installer, (int) strpos($installer, 'public static function activate'), 800);

        self::assertStringContainsString('self::migrateAcceptanceIdempotencyIndex()', $activate);
        self::assertStringContainsString('self::migrateInvoiceAcceptanceIndex()', $activate);
        self::assertStringContainsString('SchemaRequirements::hasIndex', $installer);
    }

    public function test_acceptance_audits_canonical_business_and_invoice_context_before_insert(): void
    {
        $repository = $this->read('src/WordPress/ProposalRepository.php');
        $accept = substr($repository, (int) strpos($repository, 'public function accept'), 7000);

        foreach (['business_profile_hash', 'invoice_total_minor', "'version' =>"] as $field) {
            self::assertStringContainsString($field, $accept);
        }
        self::assertLessThan(strpos($accept, '$wpdb->insert($acceptances'), strpos($accept, "'proposal.accepted'"));
    }

    public function test_final_email_flag_skips_only_ses_after_persisting_the_artifact(): void
    {
        $processor = $this->read('src/Infrastructure/ArtifactProcessor.php');

        $persistence = strpos($processor, "'kms_signature'");
        $flag = strpos($processor, 'finalEmailEnabled()');
        self::assertNotFalse($persistence);
        self::assertNotFalse($flag);
        self::assertLessThan($flag, $persistence);
        self::assertStringContainsString("'final_email.skipped'", $processor);
        self::assertStringContainsString("'reason' => 'feature_disabled'", $processor);
        self::assertStringContainsString('ArtifactState::shouldGenerate', $processor);
        self::assertStringContainsString("'final_email.sent'", $processor);
        $finalized = strpos($processor, "appendAudit((int) \$job['version_id'], 'artifact.finalized'");
        $email = strpos($processor, '$this->sendFinalEmail($acceptance, $pdf, $region)');
        self::assertNotFalse($finalized);
        self::assertNotFalse($email);
        self::assertLessThan($email, $finalized);
    }

    public function test_duplicate_and_migration_copy_only_generic_invoice_items(): void
    {
        $duplicator = $this->read('src/WordPress/ProposalDuplicator.php');
        $migration = $this->read('src/WordPress/ProposalMigrationCommand.php');

        self::assertStringContainsString("'invoice_items' =>", $duplicator);
        self::assertStringContainsString("'invoice_items'", $migration);
        foreach (['business_payload', 'e7_proposal_acceptances', 'e7_proposal_invoices'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $duplicator);
            self::assertStringNotContainsString($sensitive, $migration);
        }
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertNotFalse($contents, 'Expected plugin file: ' . $path);
        return $contents;
    }
}
