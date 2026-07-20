<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_declares_a_site_scoped_wordpress_plugin(): void
    {
        $plugin = $this->read('e7-propostas-core.php');

        self::assertStringContainsString('Plugin Name: E7 Propostas Core', $plugin);
        self::assertStringContainsString('register_activation_hook', $plugin);
        self::assertStringContainsString('E7Propostas\\WordPress\\Plugin', $plugin);
    }

    public function test_registers_a_private_gutenberg_proposal_type(): void
    {
        $postType = $this->read('src/WordPress/ProposalPostType.php');

        self::assertStringContainsString("register_post_type('e7_proposal'", $postType);
        self::assertMatchesRegularExpression("/'public'\s*=>\s*false/", $postType);
        self::assertMatchesRegularExpression("/'publicly_queryable'\s*=>\s*false/", $postType);
        self::assertMatchesRegularExpression("/'exclude_from_search'\s*=>\s*true/", $postType);
        self::assertMatchesRegularExpression("/'show_in_rest'\s*=>\s*true/", $postType);
    }

    public function test_installs_private_tables_for_versions_sessions_otps_and_evidence(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');

        foreach (['e7_proposal_settings', 'e7_proposal_versions', 'e7_proposal_sessions', 'e7_proposal_otps', 'e7_proposal_acceptances', 'e7_proposal_audit_events', 'e7_proposal_jobs', 'e7_proposal_rate_limits'] as $table) {
            self::assertStringContainsString($table, $installer);
        }
        self::assertStringContainsString("SCHEMA_VERSION = '1.7.3'", $installer);
        self::assertStringContainsString('share_code char(8) NULL', $installer);
        self::assertStringContainsString('UNIQUE KEY share_code (share_code)', $installer);
        self::assertStringContainsString("signer_email varchar(254) NOT NULL DEFAULT ''", $installer);
        self::assertStringContainsString("signer_phone varchar(32) NOT NULL DEFAULT ''", $installer);
        self::assertStringContainsString("destination varchar(254) NOT NULL DEFAULT ''", $installer);
        self::assertStringContainsString('share_code IS NULL', $this->read('src/WordPress/ProposalRepository.php'));
    }

    public function test_deployment_hardening_disables_xmlrpc_and_php_execution_in_uploads(): void
    {
        $hardening = $this->read('deploy/e7-security-hardening.php');
        $uploads = $this->read('deploy/uploads.htaccess');
        $root = $this->read('deploy/root.htaccess');

        self::assertStringContainsString('XMLRPC_REQUEST', $hardening);
        self::assertStringContainsString("add_filter('xmlrpc_methods'", $hardening);
        self::assertStringContainsString("unset(\$headers['X-Pingback'])", $hardening);
        self::assertStringContainsString("header_remove('X-Powered-By')", $hardening);
        self::assertStringContainsString('FilesMatch', $uploads);
        self::assertMatchesRegularExpression('/php\\[0-9\\]/', $uploads);
        self::assertStringContainsString('e7-proposals-import', $uploads);
        self::assertStringContainsString('local-xdebuginfo.php', $root);
        self::assertStringContainsString('wp-config.php', $root);
    }

    public function test_schema_migrations_defer_rewrite_flush_until_wordpress_init(): void
    {
        $installer = $this->read('src/WordPress/Installer.php');

        self::assertStringContainsString('self::scheduleRewriteFlush()', $installer);
        self::assertStringContainsString("add_action('init'", $installer);
        self::assertStringContainsString('flush_rewrite_rules(false)', $installer);
    }

    public function test_cli_migration_clones_only_proposal_content_and_safe_settings(): void
    {
        $plugin = $this->read('src/WordPress/Plugin.php');
        $migration = $this->read('src/WordPress/ProposalMigrationCommand.php');

        self::assertStringContainsString("e7-propostas export", $plugin);
        self::assertStringContainsString("e7-propostas import", $plugin);
        self::assertStringContainsString('_e7_migration_source_id', $migration);
        self::assertStringContainsString('wp_insert_post', $migration);
        self::assertStringContainsString('saveSettings', $migration);
        self::assertStringContainsString('passwords->hash', $migration);
        self::assertStringNotContainsString('e7_proposal_sessions', $migration);
        self::assertStringNotContainsString('e7_proposal_otps', $migration);
        self::assertStringNotContainsString('e7_proposal_acceptances', $migration);
        $bootstrap = $this->read('deploy/import-proposals.php');
        self::assertStringContainsString('Installer::ensureSchema()', $bootstrap);
        self::assertStringContainsString('new ProposalMigrationCommand', $bootstrap);
        self::assertStringContainsString('E7_PROPOSALS_IMPORT_EXPECTED_HOST', $bootstrap);
        self::assertStringContainsString('switch_to_blog', $bootstrap);
        $cleanup = $this->read('deploy/cleanup-misrouted-proposals.php');
        self::assertStringContainsString('_e7_migration_source_id', $cleanup);
        self::assertStringContainsString('wp_delete_post', $cleanup);
    }

    public function test_exposes_only_versioned_public_workflow_routes(): void
    {
        $rest = $this->read('src/WordPress/RestController.php');

        self::assertStringContainsString('e7-propostas/v1', $rest);
        self::assertStringContainsString('/access/password', $rest);
        self::assertStringContainsString('/otp/send', $rest);
        self::assertStringContainsString('/otp/verify', $rest);
        self::assertStringContainsString('/accept', $rest);
        self::assertStringContainsString("'HttpOnly' => true", $rest);
        self::assertStringContainsString("'SameSite' => 'Strict'", $rest);
        self::assertStringContainsString("(string) \$request->get_header('origin')", $rest);
    }

    public function test_signer_selects_the_otp_channel_instead_of_the_proposal_admin(): void
    {
        $admin = $this->read('src/WordPress/AdminMetaBox.php');
        $rest = $this->read('src/WordPress/RestController.php');

        self::assertStringContainsString("'client_phone' =>", $admin);
        self::assertStringNotContainsString('name="e7_proposal[otp_policy]"', $admin);
        self::assertStringContainsString("get_param('channel')", $rest);
        self::assertStringContainsString("get_param('destination')", $rest);
        self::assertStringContainsString('OtpDestination::from', $rest);
    }

    public function test_signer_email_is_optional_when_the_proposal_is_published(): void
    {
        $repository = $this->read('src/WordPress/ProposalRepository.php');
        $publish = substr($repository, (int) strpos($repository, 'public function publish'), 900);

        self::assertStringContainsString("(\$settings['password_hash'] ?? '') === ''", $publish);
        self::assertStringNotContainsString('is_email', $publish);
    }

    public function test_private_routes_disable_caches_referrers_and_indexing(): void
    {
        $routes = $this->read('src/WordPress/PublicRoutes.php');

        self::assertStringContainsString('no-store, private', $routes);
        self::assertStringContainsString('noindex, nofollow', $routes);
        self::assertStringContainsString('no-referrer', $routes);
        self::assertStringContainsString("^p/([A-Za-z0-9]{8})/?$", $routes);
        self::assertStringNotContainsString("^p/([a-f0-9]{64})/?$", $routes);
        self::assertStringContainsString("^verify/([a-f0-9]{32})/?$", $routes);
    }

    public function test_blocks_the_gutenberg_proposal_rest_routes_for_visitors(): void
    {
        $plugin = $this->read('src/WordPress/Plugin.php');

        self::assertStringContainsString("add_filter('rest_pre_dispatch'", $plugin);
        self::assertStringContainsString("str_starts_with(\$request->get_route(), '/wp/v2/e7_proposal')", $plugin);
        self::assertStringContainsString("current_user_can('e7_edit_proposals')", $plugin);
        self::assertStringContainsString("'status' => 404", $plugin);
        self::assertStringContainsString("['edit_post', 'delete_post', 'e7_edit_proposal', 'e7_delete_proposal']", $plugin);
    }

    public function test_admin_proposal_list_exposes_the_current_shareable_link(): void
    {
        $path = $this->root . '/src/WordPress/ProposalAdminList.php';
        self::assertFileExists($path);

        $list = $this->read('src/WordPress/ProposalAdminList.php');
        $plugin = $this->read('src/WordPress/Plugin.php');

        self::assertStringContainsString('manage_e7_proposal_posts_columns', $list);
        self::assertStringContainsString('manage_e7_proposal_posts_custom_column', $list);
        self::assertStringContainsString('e7_share_link', $list);
        self::assertStringContainsString('getShareCode', $list);
        self::assertStringContainsString("home_url('/p/'", $list);
        self::assertStringNotContainsString("['public_token']", $list);
        self::assertStringContainsString('Copiar link', $list);
        self::assertStringContainsString('new ProposalAdminList($repository, $invoiceRepository)', $plugin);
    }

    public function test_password_access_resolves_the_stable_share_code(): void
    {
        $controller = $this->read('src/WordPress/RestController.php');

        self::assertStringContainsString("get_param('code')", $controller);
        self::assertStringContainsString('findCurrentByShareCode', $controller);
        self::assertStringNotContainsString("get_param('token')", $controller);
        self::assertStringContainsString("'ip|'", $controller);
        self::assertStringContainsString('registerRateFailure($ipScope, 20, HOUR_IN_SECONDS)', $controller);
    }

    public function test_admin_password_field_has_no_minimum_length_rule(): void
    {
        $admin = $this->read('src/WordPress/AdminMetaBox.php');

        self::assertStringNotContainsString('minlength="8"', $admin);
        self::assertStringNotContainsString('Mínimo de 8 caracteres', $admin);
    }

    public function test_proposal_editor_uses_a_wider_settings_sidebar(): void
    {
        $admin = $this->read('src/WordPress/AdminMetaBox.php');
        $plugin = $this->read('src/WordPress/Plugin.php');

        self::assertStringContainsString("add_action('admin_enqueue_scripts'", $plugin);
        self::assertStringContainsString('post-type-e7_proposal', $admin);
        self::assertStringContainsString('width: 400px', $admin);
        self::assertStringContainsString('.interface-complementary-area__fill', $admin);
        self::assertStringContainsString('width:400px!important', $admin);
        self::assertStringNotContainsString('.interface-complementary-area{width:100%}', $admin);
    }

    public function test_marks_only_the_password_as_required_to_generate_the_link(): void
    {
        $admin = $this->read('src/WordPress/AdminMetaBox.php');

        self::assertStringContainsString('e7-required-marker', $admin);
        self::assertSame(1, substr_count($admin, 'class="e7-required-marker"'));
        self::assertStringContainsString("__('Obrigatório para gerar o link'", $admin);
        self::assertMatchesRegularExpression('/Senha da proposta.+e7-required-marker/s', $admin);
        self::assertStringContainsString("'client_email' => __('E-mail do signatário (opcional)'", $admin);
        self::assertStringContainsString("'client_phone' => __('Telefone do signatário com DDI (opcional)'", $admin);
    }

    public function test_client_email_is_optional_when_publishing(): void
    {
        $repository = $this->read('src/WordPress/ProposalRepository.php');
        $publisher = $this->read('src/WordPress/SnapshotPublisher.php');
        $admin = $this->read('src/WordPress/AdminMetaBox.php');

        self::assertStringNotContainsString("! is_email((string) (\$settings['client_email'] ?? ''))", $repository);
        self::assertStringNotContainsString('e-mail válido antes de publicar', $publisher);
        self::assertStringContainsString('E-mail do signatário (opcional)', $admin);
    }

    public function test_signed_proposals_are_marked_and_guarded_from_wordpress_saves(): void
    {
        $guard = $this->read('src/WordPress/ProposalAdminGuard.php');
        $plugin = $this->read('src/WordPress/Plugin.php');

        self::assertStringContainsString('wp_insert_post_data', $guard);
        self::assertStringContainsString('load-post.php', $guard);
        self::assertStringContainsString('display_post_states', $guard);
        self::assertStringContainsString("__('Assinada'", $guard);
        self::assertStringContainsString('Esta proposta já foi assinada e não pode ser alterada. Duplique-a para criar uma nova proposta.', $guard);
        self::assertStringContainsString('new ProposalAdminGuard($repository)', $plugin);
    }

    public function test_proposals_can_be_duplicated_without_client_or_security_data(): void
    {
        $duplicator = $this->read('src/WordPress/ProposalDuplicator.php');
        $plugin = $this->read('src/WordPress/Plugin.php');

        self::assertStringContainsString('post_row_actions', $duplicator);
        self::assertStringContainsString('admin_post_e7_duplicate_proposal', $duplicator);
        self::assertStringContainsString('check_admin_referer', $duplicator);
        self::assertStringContainsString("'client_name' => ''", $duplicator);
        self::assertStringContainsString("'client_company' => ''", $duplicator);
        self::assertStringContainsString("'client_email' => ''", $duplicator);
        self::assertStringContainsString("'client_phone' => ''", $duplicator);
        self::assertStringContainsString("'expires_at' => ''", $duplicator);
        self::assertStringContainsString("'post_status' => 'draft'", $duplicator);
        self::assertStringContainsString("' — Cópia'", $duplicator);
        self::assertStringContainsString('new ProposalDuplicator($repository)', $plugin);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertNotFalse($contents, 'Expected plugin file: ' . $path);

        return $contents;
    }
}
