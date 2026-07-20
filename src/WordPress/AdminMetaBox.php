<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\MoneyDecimal;
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
        wp_add_inline_style('wp-edit-blocks', '.post-type-e7_proposal .interface-interface-skeleton__sidebar{width: 400px}.post-type-e7_proposal .interface-complementary-area__fill,.post-type-e7_proposal .interface-complementary-area{width:400px!important}.e7-required-marker{color:#b32d2e;margin-left:4px}.e7-invoice-items[hidden]{display:none!important}.e7-invoice-row{border:1px solid #dcdcde;margin:8px 0;padding:8px}.e7-invoice-row-actions{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}.e7-invoice-total{display:flex;justify-content:space-between;margin:10px 0}');
        wp_add_inline_script('wp-edit-post', <<<'JS'
(() => {
    const init = () => {
        const root = document.querySelector('[data-e7-invoice-items]');
        const locale = document.getElementById('e7_locale');
        const currency = document.getElementById('e7_currency');
        if (!root || !locale || !currency) return;
        const rows = () => Array.from(root.querySelectorAll('[data-e7-invoice-row]'));
        const reindex = () => rows().forEach((row, index) => {
            row.querySelectorAll('[data-e7-item-field]').forEach((input) => {
                const field = input.dataset.e7ItemField;
                input.name = `e7_proposal[invoice_items][${index}][${field}]`;
            });
        });
        const parseMinor = (raw) => {
            const maxMinor = 9223372036854775807n;
            const value = raw.trim();
            const match = value.match(/^\d+(?:[.,](\d{1,2}))?$/);
            if (!match) return null;
            const parts = value.split(/[.,]/);
            const fraction = (parts[1] || '').padEnd(2, '0');
            const minor = (BigInt(parts[0]) * 100n) + BigInt(fraction || '0');
            return minor > 0n && minor <= maxMinor ? minor : null;
        };
        const formatMinor = (minor) => {
            const major = (minor / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return `${major}.${(minor % 100n).toString().padStart(2, '0')}`;
        };
        const updateTotal = () => {
            let total = 0n;
            let valid = true;
            rows().forEach((row) => {
                const input = row.querySelector('[data-e7-item-field="amount"]');
                if (!input || input.value.trim() === '') return;
                const amount = parseMinor(input.value);
                if (amount === null) valid = false;
                else total += amount;
            });
            root.querySelector('[data-e7-invoice-total]').textContent = valid ? `€${formatMinor(total)}` : '—';
        };
        const syncScope = () => {
            const enabled = locale.value === 'en_IE' && currency.value === 'EUR';
            root.hidden = !enabled;
            root.querySelectorAll('input,button').forEach((control) => { control.disabled = !enabled; });
        };
        root.addEventListener('click', (event) => {
            const button = event.target.closest('button');
            if (!button) return;
            const row = button.closest('[data-e7-invoice-row]');
            if (button.matches('[data-e7-add-invoice-item]')) {
                const template = root.querySelector('template');
                root.querySelector('[data-e7-invoice-rows]').insertAdjacentHTML('beforeend', template.innerHTML);
            } else if (row && button.matches('[data-e7-remove-invoice-item]')) {
                if (rows().length === 1) row.querySelectorAll('input').forEach((input) => { input.value = ''; });
                else row.remove();
            } else if (row && button.matches('[data-e7-move-up]') && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (row && button.matches('[data-e7-move-down]') && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            } else {
                return;
            }
            reindex();
            updateTotal();
        });
        root.addEventListener('input', updateTotal);
        locale.addEventListener('change', syncScope);
        currency.addEventListener('change', syncScope);
        reindex();
        syncScope();
        updateTotal();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
JS);
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
        $locale = (string) ($settings['locale'] ?? 'pt_BR');
        $currency = (string) ($settings['currency'] ?? 'BRL');
        $invoiceEnabled = $locale === 'en_IE' && $currency === 'EUR';
        $controlState = $invoiceEnabled ? '' : ' disabled';
        echo '</select></p><hr><fieldset class="e7-invoice-items" data-e7-invoice-items data-field="e7_proposal[invoice_items]"' . ($invoiceEnabled ? '' : ' hidden') . '><legend><strong>' . esc_html__('Itens da fatura', 'e7-propostas') . '</strong></legend><p><small>' . esc_html__('Informe valores em euros, por exemplo 1500.00. Obrigatório ao publicar em en_IE/EUR.', 'e7-propostas') . '</small></p><div data-e7-invoice-rows>';
        $invoiceItems = is_array($settings['invoice_items'] ?? null) ? $settings['invoice_items'] : [];
        $rows = max(1, count($invoiceItems));
        for ($index = 0; $index < $rows; $index++) {
            $item = is_array($invoiceItems[$index] ?? null) ? $invoiceItems[$index] : [];
            $amount = isset($item['amount_minor']) && is_int($item['amount_minor']) ? MoneyDecimal::formatInput($item['amount_minor']) : '';
            printf('<div class="e7-invoice-row" data-e7-invoice-row><label>%1$s<input class="widefat" data-e7-item-field="description" name="e7_proposal[invoice_items][%3$d][description]" type="text" maxlength="500" value="%2$s"%5$s></label><label>%6$s<input class="widefat" data-e7-item-field="amount" name="e7_proposal[invoice_items][%3$d][amount]" type="text" inputmode="decimal" placeholder="1500.00" value="%4$s"%5$s></label><div class="e7-invoice-row-actions"><button class="button button-small" type="button" data-e7-move-up%5$s>%7$s</button><button class="button button-small" type="button" data-e7-move-down%5$s>%8$s</button><button class="button button-small" type="button" data-e7-remove-invoice-item%5$s>%9$s</button></div></div>', esc_html__('Descrição', 'e7-propostas'), esc_attr((string) ($item['description'] ?? '')), $index, esc_attr($amount), $controlState, esc_html__('Valor (EUR)', 'e7-propostas'), esc_html__('Subir', 'e7-propostas'), esc_html__('Descer', 'e7-propostas'), esc_html__('Remover', 'e7-propostas'));
        }
        $totalMinor = isset($settings['invoice_total_minor']) && is_int($settings['invoice_total_minor']) ? $settings['invoice_total_minor'] : 0;
        echo '</div><template><div class="e7-invoice-row" data-e7-invoice-row><label>' . esc_html__('Descrição', 'e7-propostas') . '<input class="widefat" data-e7-item-field="description" type="text" maxlength="500"></label><label>' . esc_html__('Valor (EUR)', 'e7-propostas') . '<input class="widefat" data-e7-item-field="amount" type="text" inputmode="decimal" placeholder="1500.00"></label><div class="e7-invoice-row-actions"><button class="button button-small" type="button" data-e7-move-up>' . esc_html__('Subir', 'e7-propostas') . '</button><button class="button button-small" type="button" data-e7-move-down>' . esc_html__('Descer', 'e7-propostas') . '</button><button class="button button-small" type="button" data-e7-remove-invoice-item>' . esc_html__('Remover', 'e7-propostas') . '</button></div></div></template><button class="button button-small" type="button" data-e7-add-invoice-item' . $controlState . '>' . esc_html__('Adicionar item', 'e7-propostas') . '</button><p class="e7-invoice-total"><strong>' . esc_html__('Total', 'e7-propostas') . '</strong><output data-e7-invoice-total>€' . esc_html(MoneyDecimal::formatDisplay($totalMinor)) . '</output></p></fieldset><p><label for="e7_password"><strong>' . esc_html__('Senha da proposta', 'e7-propostas') . '</strong><span class="e7-required-marker" aria-hidden="true">*</span></label><br><input class="widefat" id="e7_password" name="e7_proposal[password]" type="password" autocomplete="new-password" placeholder="' . esc_attr(($settings['password_hash'] ?? '') !== '' ? __('Definida — deixe vazio para manter', 'e7-propostas') : __('Defina uma senha', 'e7-propostas')) . '"><br><small>' . esc_html__('Obrigatório para gerar o link', 'e7-propostas') . '</small></p>';
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
            $invoiceItems = [];
            if (($input['locale'] ?? '') === 'en_IE' && ($input['currency'] ?? '') === 'EUR') {
                foreach (is_array($input['invoice_items'] ?? null) ? $input['invoice_items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $description = is_scalar($item['description'] ?? '') ? (string) $item['description'] : '';
                    $amount = is_scalar($item['amount'] ?? '') ? trim((string) $item['amount']) : '';
                    if (trim($description) === '' && $amount === '') {
                        continue;
                    }
                    $invoiceItems[] = ['description' => $description, 'amount_minor' => MoneyDecimal::parse($amount)];
                }
            }
            $input['invoice_items'] = $invoiceItems;
            $this->repository->saveSettings($postId, $input, $password !== '' ? $this->passwords->hash($password) : null);
        } catch (\InvalidArgumentException|\DomainException $error) {
            set_transient('e7_proposal_admin_error_' . get_current_user_id(), $error->getMessage(), 60);
        }
    }
}
