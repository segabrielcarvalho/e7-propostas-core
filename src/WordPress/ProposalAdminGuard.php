<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class ProposalAdminGuard
{
    private const LOCKED_MESSAGE = 'Esta proposta já foi assinada e não pode ser alterada. Duplique-a para criar uma nova proposta.';

    public function __construct(private readonly ProposalRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('load-post.php', [$this, 'redirectAcceptedEditor']);
        add_filter('wp_insert_post_data', [$this, 'preserveAcceptedPost'], 10, 4);
        add_filter('display_post_states', [$this, 'postStates'], 10, 2);
        add_action('admin_notices', [$this, 'notice']);
        add_filter('rest_pre_dispatch', [$this, 'blockAcceptedRestMutation'], 9, 3);
    }

    public function redirectAcceptedEditor(): void
    {
        $postId = absint($_GET['post'] ?? 0);
        $action = sanitize_key((string) ($_GET['action'] ?? 'edit'));
        $post = $postId > 0 ? get_post($postId) : null;
        if ($action !== 'edit' || ! $post instanceof \WP_Post || $post->post_type !== 'e7_proposal' || ! $this->repository->isAcceptedPost($postId)) {
            return;
        }

        wp_safe_redirect(add_query_arg([
            'post_type' => 'e7_proposal',
            'e7_notice' => 'accepted',
        ], admin_url('edit.php')));
        exit;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $postarr @return array<string, mixed> */
    public function preserveAcceptedPost(array $data, array $postarr, array $unsanitizedPostarr, bool $update): array
    {
        $postId = absint($postarr['ID'] ?? 0);
        if (! $update || $postId < 1 || ($data['post_type'] ?? '') !== 'e7_proposal' || ! $this->repository->isAcceptedPost($postId)) {
            return $data;
        }

        $existing = get_post($postId, ARRAY_A);
        if (! is_array($existing)) {
            return $data;
        }

        foreach (['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order'] as $field) {
            if (array_key_exists($field, $existing)) {
                $data[$field] = $existing[$field];
            }
        }
        set_transient('e7_proposal_admin_error_' . get_current_user_id(), self::LOCKED_MESSAGE, 60);
        return $data;
    }

    /** @param array<string, string> $states @return array<string, string> */
    public function postStates(array $states, \WP_Post $post): array
    {
        if ($post->post_type === 'e7_proposal' && $this->repository->isAcceptedPost($post->ID)) {
            $states['e7_signed'] = __('Assinada', 'e7-propostas');
        }
        return $states;
    }

    public function notice(): void
    {
        $notice = sanitize_key((string) ($_GET['e7_notice'] ?? ''));
        if ($notice === 'accepted') {
            echo '<div class="notice notice-warning"><p>' . esc_html__(self::LOCKED_MESSAGE, 'e7-propostas') . '</p></div>';
        } elseif ($notice === 'duplicated') {
            echo '<div class="notice notice-success"><p>' . esc_html__('A cópia foi criada como rascunho. Revise o conteúdo, pois ele pode conter referências ao cliente anterior.', 'e7-propostas') . '</p></div>';
        }
    }

    public function blockAcceptedRestMutation(mixed $result, \WP_REST_Server $server, \WP_REST_Request $request): mixed
    {
        if (! in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || preg_match('#^/wp/v2/e7_proposal/(?P<id>\d+)$#', $request->get_route(), $matches) !== 1
            || ! $this->repository->isAcceptedPost((int) $matches['id'])) {
            return $result;
        }

        return new \WP_Error('e7_proposal_accepted', __(self::LOCKED_MESSAGE, 'e7-propostas'), ['status' => 409]);
    }
}
