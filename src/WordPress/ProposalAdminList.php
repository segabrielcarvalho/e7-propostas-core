<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class ProposalAdminList
{
    private const COLUMN = 'e7_share_link';

    public function __construct(private readonly ProposalRepository $repository)
    {
    }

    public function register(): void
    {
        add_filter('manage_e7_proposal_posts_columns', [$this, 'columns']);
        add_action('manage_e7_proposal_posts_custom_column', [$this, 'render'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /** @param array<string, string> $columns @return array<string, string> */
    public function columns(array $columns): array
    {
        $result = [];

        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $result[self::COLUMN] = __('Link compartilhável', 'e7-propostas');
            }

            $result[$key] = $label;
        }

        if (! isset($result[self::COLUMN])) {
            $result[self::COLUMN] = __('Link compartilhável', 'e7-propostas');
        }

        return $result;
    }

    public function render(string $column, int $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }

        $version = $this->repository->latestForPost($postId);
        $code = $this->repository->getShareCode($postId);
        if (! is_array($version) || $code === null) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Publique a proposta para gerar o link.', 'e7-propostas') . '</span>';
            return;
        }

        $url = home_url('/p/' . rawurlencode($code) . '/');

        echo '<div class="e7-share-link">';
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($url) . '">' . esc_html($url) . '</a>';
        echo '<button type="button" class="button button-small" data-e7-copy-link data-url="' . esc_attr($url) . '">' . esc_html__('Copiar link', 'e7-propostas') . '</button>';
        echo '</div>';
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        $screen = get_current_screen();
        if ($hookSuffix !== 'edit.php' || ! $screen instanceof \WP_Screen || $screen->post_type !== 'e7_proposal') {
            return;
        }

        wp_enqueue_script('wp-dom-ready');
        wp_add_inline_script('wp-dom-ready', $this->copyScript());
        wp_add_inline_style('common', <<<'CSS'
.column-e7_share_link{width:30%}.e7-share-link{display:flex;align-items:center;gap:8px;max-width:100%}.e7-share-link a{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.e7-share-link .button{flex:none}
CSS);
    }

    private function copyScript(): string
    {
        $copied = wp_json_encode(__('Copiado!', 'e7-propostas'), JSON_THROW_ON_ERROR);
        $copy = wp_json_encode(__('Copiar link', 'e7-propostas'), JSON_THROW_ON_ERROR);

        return <<<JS
document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-e7-copy-link]');
    if (!button) return;

    const url = button.dataset.url || '';
    try {
        await navigator.clipboard.writeText(url);
    } catch (error) {
        const input = document.createElement('textarea');
        input.value = url;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
    }

    button.textContent = {$copied};
    window.setTimeout(function () { button.textContent = {$copy}; }, 1600);
});
JS;
    }
}
