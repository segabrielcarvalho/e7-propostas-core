<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InvoiceAdminContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_admin_uses_a_dedicated_proposals_page_capability_nonce_and_safe_post_handler(): void
    {
        $admin = $this->read('src/WordPress/InvoiceAdmin.php');

        self::assertStringContainsString("add_submenu_page('edit.php?post_type=e7_proposal'", $admin);
        self::assertStringContainsString('e7_manage_proposal_invoices', $admin);
        self::assertStringContainsString("admin_post_e7_invoice_action", $admin);
        self::assertStringContainsString('check_admin_referer', $admin);
        self::assertStringContainsString('current_user_can', $admin);
        self::assertStringContainsString("REQUEST_METHOD'] ?? '') !== 'POST'", $admin);
    }

    public function test_panel_has_lifecycle_vies_supplier_and_readonly_item_controls(): void
    {
        $admin = $this->read('src/WordPress/InvoiceAdmin.php');

        foreach (['Save draft', 'Issue invoice', 'Retry', 'Cancel invoice', 'Create replacement', 'Recheck VIES', 'Supplier profile', 'readonly'] as $control) {
            self::assertStringContainsString($control, $admin);
        }
        self::assertStringContainsString('legacy_confirmation', $admin);
        self::assertStringNotContainsString('sendEmail', $admin);
        self::assertStringNotContainsString('NFS-e', $admin);
    }

    public function test_proposal_invoice_items_are_scoped_dynamic_and_use_eur_major_units(): void
    {
        $admin = $this->read('src/WordPress/AdminMetaBox.php');

        self::assertStringContainsString('MoneyDecimal::parse', $admin);
        self::assertStringContainsString('MoneyDecimal::formatInput', $admin);
        self::assertStringContainsString('data-e7-invoice-items', $admin);
        self::assertStringContainsString('data-e7-invoice-row', $admin);
        self::assertStringContainsString('data-e7-add-invoice-item', $admin);
        self::assertStringContainsString('data-e7-remove-invoice-item', $admin);
        self::assertStringContainsString('data-e7-move-up', $admin);
        self::assertStringContainsString('data-e7-move-down', $admin);
        self::assertStringContainsString('data-e7-invoice-total', $admin);
        self::assertStringContainsString("locale === 'en_IE'", $admin);
        self::assertStringContainsString("currency === 'EUR'", $admin);
        self::assertStringContainsString('wp_add_inline_script', $admin);
        self::assertStringContainsString('9223372036854775807n', $admin);
        self::assertStringNotContainsString('Valor em unidade menor', $admin);
    }

    public function test_legacy_items_use_the_same_readable_eur_decimal_contract(): void
    {
        $admin = $this->read('src/WordPress/InvoiceAdmin.php');

        self::assertStringContainsString('MoneyDecimal::parse', $admin);
        self::assertStringContainsString('MoneyDecimal::formatInput', $admin);
        self::assertStringContainsString('MoneyDecimal::formatDisplay', $admin);
        self::assertStringContainsString("[amount]", $admin);
        self::assertStringNotContainsString('placeholder="Amount minor"', $admin);
        self::assertStringContainsString("\$editable = \$status === 'draft'", $admin);
        self::assertStringContainsString('type="text" readonly', $admin);
    }

    public function test_proposal_list_adds_invoice_status_and_prepare_invoice_action(): void
    {
        $list = $this->read('src/WordPress/ProposalAdminList.php');

        self::assertStringContainsString('e7_invoice_status', $list);
        self::assertStringContainsString('Prepare invoice', $list);
        self::assertStringContainsString('acceptanceIdForPost', $list);
    }

    public function test_plugin_wires_invoice_admin_without_changing_the_accepted_editor_guard(): void
    {
        $plugin = $this->read('src/WordPress/Plugin.php');
        $postType = $this->read('src/WordPress/ProposalPostType.php');

        self::assertStringContainsString('new InvoiceRepository(', $plugin);
        self::assertStringContainsString('new InvoiceService(', $plugin);
        self::assertStringContainsString('new InvoiceAdmin(', $plugin);
        self::assertStringContainsString("'e7_manage_proposal_invoices'", $postType);
        self::assertStringContainsString("return ['do_not_allow'];", $plugin);
    }

    public function test_supplier_defaults_are_exact_and_contain_no_banking_fields(): void
    {
        $supplier = $this->read('src/Domain/SupplierProfile.php');

        foreach (['E7 Company Tecnologia Ltda.', '63.058.279/0001-84', 'Avenida Alvorada, 790, Apto 1508A', 'Chácaras Americanas', 'Anápolis/GO', '75103-237', 'Brazil'] as $value) {
            self::assertStringContainsString($value, $supplier);
        }
        self::assertStringNotContainsString('bank', strtolower($supplier));
        self::assertStringNotContainsString('iban', strtolower($supplier));
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, 'Expected plugin file: ' . $path);
        return $contents;
    }
}
