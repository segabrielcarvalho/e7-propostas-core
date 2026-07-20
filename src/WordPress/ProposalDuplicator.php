<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class ProposalDuplicator
{
    public function __construct(private readonly ProposalRepository $repository)
    {
    }

    public function register(): void
    {
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_action('admin_post_e7_duplicate_proposal', [$this, 'duplicate']);
    }

    /** @param array<string, string> $actions @return array<string, string> */
    public function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== 'e7_proposal' || ! current_user_can('e7_edit_proposals')) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(['action' => 'e7_duplicate_proposal', 'post' => $post->ID], admin_url('admin-post.php')),
            'e7_duplicate_proposal_' . $post->ID,
        );
        $actions['e7_duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicar proposta', 'e7-propostas') . '</a>';
        return $actions;
    }

    public function duplicate(): never
    {
        $sourceId = absint($_GET['post'] ?? 0);
        if ($sourceId < 1 || ! current_user_can('e7_edit_proposals')) {
            wp_die(esc_html__('Você não tem permissão para duplicar esta proposta.', 'e7-propostas'), '', ['response' => 403]);
        }
        check_admin_referer('e7_duplicate_proposal_' . $sourceId);

        $source = get_post($sourceId);
        if (! $source instanceof \WP_Post || $source->post_type !== 'e7_proposal') {
            wp_die(esc_html__('Proposta não encontrada.', 'e7-propostas'), '', ['response' => 404]);
        }

        $newId = $this->createCopy($sourceId, get_current_user_id());
        if (is_wp_error($newId)) {
            wp_die(esc_html($newId->get_error_message()), '', ['response' => 500]);
        }

        wp_safe_redirect(add_query_arg([
            'post' => $newId,
            'action' => 'edit',
            'e7_notice' => 'duplicated',
        ], admin_url('post.php')));
        exit;
    }

    public function createCopy(int $sourceId, int $authorId): int|\WP_Error
    {
        $source = get_post($sourceId);
        if (! $source instanceof \WP_Post || $source->post_type !== 'e7_proposal') {
            return new \WP_Error('e7_proposal_not_found', __('Proposta não encontrada.', 'e7-propostas'));
        }

        $newId = wp_insert_post([
            'post_type' => 'e7_proposal',
            'post_status' => 'draft',
            'post_title' => $source->post_title . ' — Cópia',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author' => $authorId,
        ], true);
        if (is_wp_error($newId)) {
            return $newId;
        }

        $settings = $this->repository->getSettings($sourceId);
        try {
            $this->repository->saveSettings((int) $newId, [
                'client_name' => '',
                'client_company' => '',
                'client_email' => '',
                'client_phone' => '',
                'copy_email' => (string) ($settings['copy_email'] ?? ''),
                'expires_at' => '',
                'locale' => (string) ($settings['locale'] ?? 'pt_BR'),
                'currency' => (string) ($settings['currency'] ?? 'BRL'),
                'otp_policy' => (string) ($settings['otp_policy'] ?? 'email'),
                'invoice_items' => is_array($settings['invoice_items'] ?? null) ? $settings['invoice_items'] : [],
            ], null);
        } catch (\Throwable $error) {
            wp_delete_post((int) $newId, true);
            return new \WP_Error('e7_proposal_duplicate_failed', __('Não foi possível copiar as configurações da proposta.', 'e7-propostas'));
        }

        return (int) $newId;
    }
}
