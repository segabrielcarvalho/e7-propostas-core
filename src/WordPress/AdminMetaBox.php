<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\PasswordService;

final class AdminMetaBox
{
    public function __construct(private readonly ProposalRepository $repository, private readonly PasswordService $passwords)
    {
    }

    public function register(): void
    {
        add_meta_box('e7-proposal-settings', __('Configuração segura', 'e7-propostas'), [$this, 'render'], 'e7_proposal', 'side', 'high');
    }

    public function enqueueAssets(string $hook): void
    {
        $screen = get_current_screen();
        if (! in_array($hook, ['post.php', 'post-new.php'], true) || ! $screen instanceof \WP_Screen || $screen->post_type !== 'e7_proposal') {
            return;
        }
        wp_add_inline_style('wp-edit-blocks', '.post-type-e7_proposal .interface-interface-skeleton__sidebar{width: 400px}.post-type-e7_proposal .interface-complementary-area__fill,.post-type-e7_proposal .interface-complementary-area{width:400px!important}.e7-required-marker{color:#b32d2e;margin-left:4px}');
    }

    public function render(\WP_Post $post): void
    {
        $settings = $this->repository->getSettings($post->ID);
        $version = $this->repository->latestForPost($post->ID);
        $code = $this->repository->getShareCode($post->ID);
        wp_nonce_field('e7_proposal_settings_' . $post->ID, 'e7_proposal_settings_nonce');
        $fields = [
            'client_name' => __('Nome do cliente', 'e7-propostas'),
            'client_company' => __('Empresa', 'e7-propostas'),
            'client_email' => __('E-mail do signatário (opcional)', 'e7-propostas'),
            'client_phone' => __('Telefone do signatário com DDI (opcional)', 'e7-propostas'),
            'copy_email' => __('Cópia para a E7', 'e7-propostas'),
            'expires_at' => __('Validade', 'e7-propostas'),
        ];
        echo '<div class="e7-proposal-admin-fields">';
        foreach ($fields as $name => $label) {
            $type = $name === 'expires_at' ? 'date' : ($name === 'client_phone' ? 'tel' : (str_contains($name, 'email') ? 'email' : 'text'));
            printf('<p><label for="e7_%1$s"><strong>%2$s</strong></label><br><input class="widefat" id="e7_%1$s" name="e7_proposal[%1$s]" type="%3$s" value="%4$s"></p>', esc_attr($name), esc_html($label), esc_attr($type), esc_attr((string) ($settings[$name] ?? '')));
        }
        echo '<p><label for="e7_locale"><strong>' . esc_html__('Idioma', 'e7-propostas') . '</strong></label><br><select class="widefat" id="e7_locale" name="e7_proposal[locale]">';
        foreach (['pt_BR' => 'Português (Brasil)', 'en_IE' => 'English (Ireland)'] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected(($settings['locale'] ?? 'pt_BR'), $value, false), esc_html($label));
        }
        echo '</select></p><p><label for="e7_currency"><strong>' . esc_html__('Moeda', 'e7-propostas') . '</strong></label><br><select class="widefat" id="e7_currency" name="e7_proposal[currency]">';
        foreach (['BRL', 'EUR', 'USD'] as $value) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected(($settings['currency'] ?? 'BRL'), $value, false), esc_html($value));
        }
        echo '</select></p><p><label for="e7_password"><strong>' . esc_html__('Senha da proposta', 'e7-propostas') . '</strong><span class="e7-required-marker" aria-hidden="true">*</span></label><br><input class="widefat" id="e7_password" name="e7_proposal[password]" type="password" autocomplete="new-password" placeholder="' . esc_attr(($settings['password_hash'] ?? '') !== '' ? __('Definida — deixe vazio para manter', 'e7-propostas') : __('Defina uma senha', 'e7-propostas')) . '"><br><small>' . esc_html__('Obrigatório para gerar o link', 'e7-propostas') . '</small></p>';
        if (is_array($version) && $code !== null) {
            $url = home_url('/p/' . $code . '/');
            echo '<hr><p><strong>' . esc_html__('Link privado atual', 'e7-propostas') . '</strong></p><input class="widefat" type="text" readonly value="' . esc_attr($url) . '"><p><small>' . esc_html(sprintf(__('Versão %d · %s', 'e7-propostas'), (int) $version['version_no'], (string) $version['status'])) . '</small></p>';
        }
        echo '<p><small>' . esc_html__('A senha nunca poderá ser visualizada depois de salva; apenas substituída.', 'e7-propostas') . '</small></p></div>';
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'e7_proposal' || wp_is_post_autosave($postId) || wp_is_post_revision($postId) || ! current_user_can('e7_edit_proposal', $postId)) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['e7_proposal_settings_nonce'] ?? ''));
        if (! wp_verify_nonce($nonce, 'e7_proposal_settings_' . $postId)) {
            return;
        }
        $input = isset($_POST['e7_proposal']) && is_array($_POST['e7_proposal']) ? wp_unslash($_POST['e7_proposal']) : [];
        $password = (string) ($input['password'] ?? '');
        try {
            $this->repository->saveSettings($postId, $input, $password !== '' ? $this->passwords->hash($password) : null);
        } catch (\InvalidArgumentException|\DomainException $error) {
            set_transient('e7_proposal_admin_error_' . get_current_user_id(), $error->getMessage(), 60);
        }
    }
}
