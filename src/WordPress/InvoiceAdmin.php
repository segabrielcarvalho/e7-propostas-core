<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\SupplierProfile;

final class InvoiceAdmin
{
    private const CAPABILITY = 'e7_manage_proposal_invoices';
    private const PAGE = 'e7-commercial-invoice';
    private const NONCE = 'e7_invoice_action';

    public function __construct(private readonly InvoiceRepository $repository, private readonly InvoiceService $service)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_e7_invoice_action', [$this, 'handle']);
    }

    public function menu(): void
    {
        add_submenu_page('edit.php?post_type=e7_proposal', __('Commercial invoices', 'e7-propostas'), __('Invoices', 'e7-propostas'), self::CAPABILITY, self::PAGE, [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage invoices.', 'e7-propostas'));
        }
        $invoiceId = isset($_GET['invoice_id']) ? absint($_GET['invoice_id']) : 0;
        $acceptanceId = isset($_GET['acceptance_id']) ? absint($_GET['acceptance_id']) : 0;
        $invoice = $invoiceId > 0 ? $this->repository->get($invoiceId) : null;
        if (! is_array($invoice) && $acceptanceId > 0) {
            $invoice = $this->repository->currentRoot($acceptanceId);
        }
        echo '<div class="wrap"><h1>' . esc_html__('Commercial invoice', 'e7-propostas') . '</h1>';
        $notice = isset($_GET['e7_invoice_notice']) ? sanitize_key((string) $_GET['e7_invoice_notice']) : '';
        if ($notice !== '') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Invoice operation completed.', 'e7-propostas') . '</p></div>';
        }
        if (is_array($invoice)) {
            $this->renderInvoice($invoice);
        } elseif ($acceptanceId > 0) {
            $this->renderPreparation($acceptanceId);
        } else {
            echo '<p>' . esc_html__('Use “Prepare invoice” from an accepted proposal.', 'e7-propostas') . '</p>';
        }
        $this->renderSupplier();
        echo '</div>';
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Method not allowed.', 'e7-propostas'), '', ['response' => 405]);
        }
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage invoices.', 'e7-propostas'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
        $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
        $invoiceId = absint($_POST['invoice_id'] ?? 0);
        $acceptanceId = absint($_POST['acceptance_id'] ?? 0);
        $actorId = get_current_user_id();
        try {
            $invoice = match ($operation) {
                'prepare' => $this->service->prepareDraft(
                    $acceptanceId,
                    isset($_POST['customer_profile']) ? $this->profileFromPost((array) wp_unslash($_POST['customer_profile'])) : null,
                    isset($_POST['invoice_items']) ? $this->itemsFromPost((array) wp_unslash($_POST['invoice_items'])) : null,
                    isset($_POST['legacy_confirmation']),
                    $actorId,
                ),
                'save_draft' => $this->service->saveDraftCustomer($invoiceId, $this->profileFromPost((array) wp_unslash($_POST['customer_profile'] ?? [])), $actorId),
                'issue' => $this->service->issue($invoiceId, isset($_POST['vies_acknowledgement']), $actorId),
                'retry' => $this->service->retry($invoiceId, $actorId),
                'cancel' => $this->service->cancel($invoiceId, $actorId),
                'replacement' => $this->service->createReplacement($invoiceId, $actorId),
                'vies' => $this->service->recheckVies($invoiceId, $actorId),
                'save_supplier' => $this->saveSupplier((array) wp_unslash($_POST['supplier_profile'] ?? [])),
                default => throw new \DomainException('Unknown invoice operation.'),
            };
            $targetId = is_array($invoice) ? (int) ($invoice['id'] ?? $invoiceId) : $invoiceId;
            $this->redirect($targetId, $acceptanceId, 'saved');
        } catch (\Throwable $error) {
            set_transient('e7_proposal_admin_error_' . $actorId, $error->getMessage(), 60);
            $this->redirect($invoiceId, $acceptanceId, 'error');
        }
    }

    /** @param array<string, mixed> $invoice */
    private function renderInvoice(array $invoice): void
    {
        $status = (string) $invoice['status'];
        echo '<h2>' . esc_html((string) ($invoice['invoice_number'] ?: __('Draft invoice', 'e7-propostas'))) . '</h2>';
        echo '<p><strong>' . esc_html__('Status', 'e7-propostas') . ':</strong> ' . esc_html($status) . '</p>';
        echo '<p><strong>Public ID:</strong> <code>' . esc_html((string) $invoice['public_id']) . '</code></p>';
        echo '<p><strong>VIES:</strong> ' . esc_html((string) ($invoice['vies_status'] ?? 'not_requested')) . ' ' . esc_html((string) ($invoice['vies_checked_at'] ?? '')) . '</p>';
        $this->openForm('vies', (int) $invoice['id']);
        submit_button(__('Recheck VIES', 'e7-propostas'), 'secondary', 'submit', false);
        echo '</form>';

        $editable = $status === 'draft';
        $this->openForm('save_draft', (int) $invoice['id']);
        $this->renderCustomerFields((array) $invoice['customer_profile'], ! $editable);
        echo '<h2>' . esc_html__('Invoice items', 'e7-propostas') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Description', 'e7-propostas') . '</th><th>' . esc_html__('Amount', 'e7-propostas') . '</th></tr></thead><tbody>';
        foreach ((array) $invoice['items'] as $item) {
            echo '<tr><td><input type="text" readonly value="' . esc_attr((string) ($item['description'] ?? '')) . '" class="large-text"></td><td><input type="text" readonly value="' . esc_attr($this->money((int) ($item['amount_minor'] ?? 0))) . '"></td></tr>';
        }
        echo '</tbody><tfoot><tr><th>Total</th><th>' . esc_html($this->money((int) $invoice['total_minor'])) . '</th></tr></tfoot></table>';
        if ($editable) {
            submit_button(__('Save draft', 'e7-propostas'));
        }
        echo '</form><hr>';

        if ($status === 'draft') {
            $this->openForm('issue', (int) $invoice['id']);
            echo '<label><input type="checkbox" name="vies_acknowledgement" value="1"> ' . esc_html__('I acknowledge the current VIES result and confirm issue.', 'e7-propostas') . '</label> ';
            submit_button(__('Issue invoice', 'e7-propostas'), 'primary', 'submit', false);
            echo '</form>';
        } elseif ($status === 'failed') {
            $this->openForm('retry', (int) $invoice['id']);
            submit_button(__('Retry', 'e7-propostas'), 'primary', 'submit', false);
            echo '</form>';
        } elseif ($status === 'issued') {
            $this->openForm('cancel', (int) $invoice['id']);
            submit_button(__('Cancel invoice', 'e7-propostas'), 'secondary', 'submit', false);
            echo '</form>';
        }
        if (in_array($status, ['issued', 'cancelled'], true)) {
            $this->openForm('replacement', (int) $invoice['id']);
            submit_button(__('Create replacement', 'e7-propostas'), 'secondary', 'submit', false);
            echo '</form>';
        }
    }

    private function renderPreparation(int $acceptanceId): void
    {
        try {
            $context = $this->repository->acceptanceContext($acceptanceId);
        } catch (\Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error->getMessage()) . '</p></div>';
            return;
        }
        $legacy = ! is_array($context['customer_profile'] ?? null) || empty($context['invoice_items']);
        echo '<h2>' . esc_html__('Prepare invoice', 'e7-propostas') . '</h2>';
        $this->openForm('prepare', 0, $acceptanceId);
        if ($legacy) {
            echo '<p>' . esc_html__('Legacy acceptance: provide the matching customer profile and invoice items once. Gutenberg content is not parsed.', 'e7-propostas') . '</p>';
            $this->renderCustomerFields([], false);
            $this->renderLegacyItems();
            echo '<p><label><input type="checkbox" name="legacy_confirmation" value="1" required> ' . esc_html__('I explicitly confirm that this profile and these items correspond to the accepted proposal.', 'e7-propostas') . '</label></p>';
        } else {
            echo '<p>' . esc_html__('Customer and item snapshots will be frozen from the acceptance.', 'e7-propostas') . '</p>';
        }
        submit_button(__('Prepare invoice', 'e7-propostas'));
        echo '</form>';
    }

    private function renderSupplier(): void
    {
        $supplier = SupplierProfile::normalize(get_option('e7_invoice_supplier_profile', SupplierProfile::defaults()));
        echo '<hr><h2>' . esc_html__('Supplier profile', 'e7-propostas') . '</h2>';
        $this->openForm('save_supplier');
        echo '<table class="form-table"><tbody>';
        foreach ($supplier as $field => $value) {
            echo '<tr><th><label for="e7_supplier_' . esc_attr($field) . '">' . esc_html(ucwords(str_replace('_', ' ', $field))) . '</label></th><td><input class="regular-text" id="e7_supplier_' . esc_attr($field) . '" name="supplier_profile[' . esc_attr($field) . ']" value="' . esc_attr($value) . '" required></td></tr>';
        }
        echo '</tbody></table>';
        submit_button(__('Save supplier profile', 'e7-propostas'));
        echo '</form>';
    }

    /** @param array<string, mixed> $profile */
    private function renderCustomerFields(array $profile, bool $readonly): void
    {
        $disabled = $readonly ? ' readonly' : '';
        $responsible = is_array($profile['responsible'] ?? null) ? $profile['responsible'] : [];
        $address = is_array($profile['registered_address'] ?? null) ? $profile['registered_address'] : [];
        $billing = is_array($profile['billing_address'] ?? null) ? $profile['billing_address'] : [];
        echo '<h2>' . esc_html__('Customer fields', 'e7-propostas') . '</h2><table class="form-table"><tbody>';
        $fields = [
            'legal_name' => __('Legal name', 'e7-propostas'), 'trading_name' => __('Trading name', 'e7-propostas'),
            'registration_number' => __('CRO number', 'e7-propostas'), 'vat_number' => __('Irish VAT', 'e7-propostas'),
            'finance_email' => __('Finance email', 'e7-propostas'), 'service_city' => __('Service city', 'e7-propostas'),
            'domain' => __('Domain', 'e7-propostas'), 'whatsapp' => __('WhatsApp', 'e7-propostas'),
        ];
        foreach ($fields as $field => $label) {
            echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" name="customer_profile[' . esc_attr($field) . ']" value="' . esc_attr((string) ($profile[$field] ?? '')) . '"' . $disabled . '></td></tr>';
        }
        foreach (['name', 'role', 'email', 'phone'] as $field) {
            echo '<tr><th>' . esc_html('Responsible ' . $field) . '</th><td><input class="regular-text" name="customer_profile[responsible][' . esc_attr($field) . ']" value="' . esc_attr((string) ($responsible[$field] ?? '')) . '"' . $disabled . '></td></tr>';
        }
        foreach (['line1', 'line2', 'city', 'county', 'eircode'] as $field) {
            echo '<tr><th>' . esc_html('Address ' . $field) . '</th><td><input class="regular-text" name="customer_profile[registered_address][' . esc_attr($field) . ']" value="' . esc_attr((string) ($address[$field] ?? '')) . '"' . $disabled . '></td></tr>';
        }
        foreach (['line1', 'line2', 'city', 'county', 'eircode'] as $field) {
            echo '<tr><th>' . esc_html('Billing ' . $field) . '</th><td><input class="regular-text" name="customer_profile[billing_address][' . esc_attr($field) . ']" value="' . esc_attr((string) ($billing[$field] ?? '')) . '"' . $disabled . '></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><label>' . esc_html__('Business type', 'e7-propostas') . ' <select name="customer_profile[type]"' . ($readonly ? ' disabled' : '') . '><option value="company"' . selected((string) ($profile['type'] ?? 'company'), 'company', false) . '>company</option><option value="sole_trader"' . selected((string) ($profile['type'] ?? ''), 'sole_trader', false) . '>sole trader</option></select></label></p>';
        foreach (['purchase_order' => (string) ($profile['purchase_order'] ?? ''), 'payer_legal_name' => (string) ($profile['payer_legal_name'] ?? '')] as $field => $value) {
            echo '<input type="hidden" name="customer_profile[' . esc_attr($field) . ']" value="' . esc_attr($value) . '">';
        }
        echo '<input type="hidden" name="customer_profile[registered_address][country_code]" value="IE"><input type="hidden" name="customer_profile[billing_address][country_code]" value="IE">';
        foreach (['billing_same_as_registered', 'payer_same_as_business', 'vat_registered'] as $boolean) {
            echo '<input type="hidden" name="customer_profile[' . esc_attr($boolean) . ']" value="0"><label><input type="checkbox" name="customer_profile[' . esc_attr($boolean) . ']" value="1"' . checked(! empty($profile[$boolean]), true, false) . ($readonly ? ' disabled' : '') . '> ' . esc_html(ucwords(str_replace('_', ' ', $boolean))) . '</label> ';
        }
        foreach (['b2b', 'ireland', 'accuracy'] as $confirmation) {
            echo '<input type="hidden" name="customer_profile[confirmations][' . esc_attr($confirmation) . ']" value="1">';
        }
    }

    private function renderLegacyItems(): void
    {
        echo '<h2>' . esc_html__('Invoice items', 'e7-propostas') . '</h2>';
        for ($index = 0; $index < 5; $index++) {
            echo '<p><input class="large-text" name="invoice_items[' . $index . '][description]" placeholder="Description"><input name="invoice_items[' . $index . '][amount_minor]" type="number" min="1" step="1" placeholder="Amount minor"></p>';
        }
    }

    private function openForm(string $operation, int $invoiceId = 0, int $acceptanceId = 0): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="e7_invoice_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        if ($invoiceId > 0) {
            echo '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">';
        }
        if ($acceptanceId > 0) {
            echo '<input type="hidden" name="acceptance_id" value="' . $acceptanceId . '">';
        }
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function profileFromPost(array $profile): array
    {
        $profile['billing_same_as_registered'] = ! empty($profile['billing_same_as_registered']);
        $profile['payer_same_as_business'] = ! empty($profile['payer_same_as_business']);
        $profile['vat_registered'] = ! empty($profile['vat_registered']);
        return $profile;
    }

    /** @param array<int, mixed> $items @return list<array<string, mixed>> */
    private function itemsFromPost(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $amount = trim((string) ($item['amount_minor'] ?? ''));
            $normalized[] = ['description' => (string) ($item['description'] ?? ''), 'amount_minor' => ctype_digit($amount) ? (int) $amount : $amount];
        }
        return $normalized;
    }

    /** @param array<string, mixed> $supplier */
    private function saveSupplier(array $supplier): array
    {
        $normalized = SupplierProfile::normalize($supplier);
        update_option('e7_invoice_supplier_profile', $normalized, false);
        return [];
    }

    private function money(int $minor): string
    {
        return '€' . number_format($minor / 100, 2, '.', ',');
    }

    private function redirect(int $invoiceId, int $acceptanceId, string $notice): never
    {
        $args = ['post_type' => 'e7_proposal', 'page' => self::PAGE, 'e7_invoice_notice' => $notice];
        if ($invoiceId > 0) {
            $args['invoice_id'] = $invoiceId;
        } elseif ($acceptanceId > 0) {
            $args['acceptance_id'] = $acceptanceId;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
        exit;
    }
}
