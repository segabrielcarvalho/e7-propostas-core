<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\AuditChain;
use E7Propostas\Domain\OtpService;
use E7Propostas\Domain\PasswordService;
use E7Propostas\Domain\ShareCodeService;
use E7Propostas\Domain\SnapshotHasher;
use E7Propostas\Domain\TokenService;
use E7Propostas\Infrastructure\ArtifactProcessor;
use E7Propostas\Infrastructure\ArtifactVerifier;
use E7Propostas\Infrastructure\ArtifactDownload;
use E7Propostas\Infrastructure\Crypto;
use E7Propostas\Infrastructure\DeliveryService;
use E7Propostas\Infrastructure\FeatureFlags;

final class Plugin
{
    private static ?self $instance = null;

    private function __construct(private readonly string $file)
    {
    }

    public static function boot(string $file): void
    {
        self::$instance ??= new self($file);
        self::$instance->register();
    }

    private function register(): void
    {
        add_action('plugins_loaded', function (): void {
            if (get_option('e7_propostas_core_enabled') !== '1') {
                return;
            }
            Installer::ensureSchema();
            $secret = defined('AUTH_KEY') ? (string) AUTH_KEY : wp_salt('auth');
            $crypto = new Crypto($secret);
            $tokens = new TokenService(wp_salt('secure_auth'));
            $repository = new ProposalRepository($crypto, $tokens, new SnapshotHasher(), new AuditChain(), new ShareCodeService());
            $artifactVerifier = new ArtifactVerifier();
            $features = new FeatureFlags();
            $passwords = new PasswordService();
            $admin = new AdminMetaBox($repository, $passwords);
            $adminList = new ProposalAdminList($repository);
            $adminGuard = new ProposalAdminGuard($repository);
            $duplicator = new ProposalDuplicator($repository);
            $publisher = new SnapshotPublisher($repository);
            $routes = new PublicRoutes($repository, $artifactVerifier, new ArtifactDownload());
            $rest = new RestController($repository, $passwords, new OtpService(wp_salt('logged_in')), new DeliveryService(), $artifactVerifier, $features);
            $artifacts = new ArtifactProcessor($repository, $features);
            $migration = new ProposalMigrationCommand($repository, $passwords);

            add_action('init', [ProposalPostType::class, 'register']);
            add_action('init', [PublicRoutes::class, 'registerRewrites']);
            add_filter('query_vars', [$routes, 'queryVars']);
            add_action('template_redirect', [$routes, 'dispatch'], 0);
            add_action('rest_api_init', [$rest, 'register']);
            add_filter('rest_pre_dispatch', [$this, 'blockPublicProposalRest'], 10, 3);
            add_filter('map_meta_cap', static function (array $caps, string $cap, int $userId, array $args) use ($repository): array {
                $candidate = $args[0] ?? null;
                $postId = is_int($candidate) ? $candidate : (is_string($candidate) && ctype_digit($candidate) ? (int) $candidate : 0);
                $post = $postId > 0 ? get_post($postId) : null;
                if (in_array($cap, ['edit_post', 'delete_post', 'e7_edit_proposal', 'e7_delete_proposal'], true) && $post instanceof \WP_Post && $post->post_type === 'e7_proposal' && $repository->isAcceptedPost($post->ID)) {
                    return ['do_not_allow'];
                }
                return $caps;
            }, 10, 4);
            add_action('add_meta_boxes', [$admin, 'register']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueueAssets']);
            add_action('save_post_e7_proposal', [$admin, 'save'], 20, 2);
            add_action('save_post_e7_proposal', [$publisher, 'publish'], 50, 2);
            $adminList->register();
            $adminGuard->register();
            $duplicator->register();
            add_action('admin_notices', [$this, 'adminNotice']);
            add_filter('wp_sitemaps_post_types', [$this, 'removePrivatePostType']);
            add_filter('wp_robots', [$this, 'privateRobots']);
            add_filter('cron_schedules', [$this, 'cronSchedules']);
            add_action('e7_propostas_process_jobs', [$artifacts, 'runDue']);
            if (getenv('E7_PROPOSTAS_EXTERNAL_WORKER') === '1') {
                wp_clear_scheduled_hook('e7_propostas_process_jobs');
            } elseif (! wp_next_scheduled('e7_propostas_process_jobs')) {
                wp_schedule_event(time() + 60, 'e7_every_minute', 'e7_propostas_process_jobs');
            }
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::add_command('e7-propostas jobs run', static function () use ($artifacts): void {
                    $processed = $artifacts->runDue();
                    \WP_CLI::success(sprintf('%d job(s) processed.', $processed));
                });
                \WP_CLI::add_command('e7-propostas export', [$migration, 'export']);
                \WP_CLI::add_command('e7-propostas import', [$migration, 'import']);
            }
        });
    }

    public function adminNotice(): void
    {
        $key = 'e7_proposal_admin_error_' . get_current_user_id();
        $message = get_transient($key);
        if (! is_string($message) || $message === '') {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    /** @param array<string, mixed> $types @return array<string, mixed> */
    public function removePrivatePostType(array $types): array
    {
        unset($types['e7_proposal']);
        return $types;
    }

    public function blockPublicProposalRest(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if (str_starts_with($request->get_route(), '/wp/v2/e7_proposal') && ! current_user_can('e7_edit_proposals')) {
            return new \WP_Error('rest_no_route', __('Rota não encontrada.', 'e7-propostas'), ['status' => 404]);
        }

        return $result;
    }

    /** @param array<string, bool> $robots @return array<string, bool> */
    public function privateRobots(array $robots): array
    {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        $robots['noarchive'] = true;
        return $robots;
    }

    /** @param array<string, array<string, int|string>> $schedules @return array<string, array<string, int|string>> */
    public function cronSchedules(array $schedules): array
    {
        $schedules['e7_every_minute'] = ['interval' => 60, 'display' => __('A cada minuto', 'e7-propostas')];
        return $schedules;
    }
}
