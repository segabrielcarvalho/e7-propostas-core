<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\ArtifactVerifier;
use E7Propostas\Infrastructure\InvoiceRoutePolicy;
use E7Propostas\Infrastructure\InvoiceSignatureEnvelope;
use PHPUnit\Framework\TestCase;

final class InvoiceRoutesTest extends TestCase
{
    public function test_public_verification_record_is_an_explicit_privacy_allowlist(): void
    {
        $invoice = $this->invoice();
        $record = InvoiceRoutePolicy::verificationRecord($invoice, true);

        self::assertSame([
            'invoice_number', 'supplier_legal_name', 'customer_legal_name', 'issued_at',
            'currency', 'total', 'status', 'artifact_hash', 'signature_verified',
            'cancelled', 'replacement_status', 'replacement_invoice_number',
        ], array_keys($record));
        self::assertSame('E7 Company Tecnologia Ltda.', $record['supplier_legal_name']);
        self::assertSame('Ross Motorcycles Limited', $record['customer_legal_name']);
        self::assertTrue($record['signature_verified']);
        foreach (['registered_address', 'vat_number', 'finance_email', 'phone', 'items', 'artifact_key', 'kms_signature'] as $sensitive) {
            self::assertArrayNotHasKey($sensitive, $record);
        }
    }

    public function test_public_verification_formats_minor_units_without_floating_point_precision_loss(): void
    {
        $invoice = $this->invoice();
        $invoice['total_minor'] = PHP_INT_MAX;

        $record = InvoiceRoutePolicy::verificationRecord($invoice, true);

        self::assertSame('92233720368547758.07', $record['total']);
    }

    public function test_cancelled_invoice_stays_verifiable_and_points_to_an_issued_replacement(): void
    {
        $invoice = $this->invoice();
        $invoice['status'] = 'cancelled';
        $invoice['cancelled_at'] = '2026-07-21 10:00:00';
        $invoice['replacement_status'] = 'issued';
        $invoice['replacement_invoice_number'] = 'E7-2026-4822';

        $record = InvoiceRoutePolicy::verificationRecord($invoice, false);

        self::assertTrue($record['cancelled']);
        self::assertSame('issued', $record['replacement_status']);
        self::assertSame('E7-2026-4822', $record['replacement_invoice_number']);
    }

    public function test_private_download_requires_issued_status_and_the_same_proposal_session_version(): void
    {
        $invoice = $this->invoice();

        self::assertTrue(InvoiceRoutePolicy::canCustomerDownload($invoice, ['version_id' => 5]));
        self::assertFalse(InvoiceRoutePolicy::canCustomerDownload($invoice, ['version_id' => 6]));
        self::assertFalse(InvoiceRoutePolicy::canCustomerDownload($invoice, null));
        $invoice['status'] = 'cancelled';
        self::assertFalse(InvoiceRoutePolicy::canCustomerDownload($invoice, ['version_id' => 5]));
    }

    public function test_invoice_verifier_uses_the_envelope_while_proposals_keep_the_legacy_artifact_hash(): void
    {
        $calls = [];
        $invoice = $this->invoice();
        $invoice['signature_payload_hash'] = InvoiceSignatureEnvelope::hash($invoice);
        $verifier = new ArtifactVerifier(static function (string $hash, string $signature) use (&$calls, $invoice): bool {
            $calls[] = [$hash, $signature];
            return $hash === $invoice['signature_payload_hash'] || $hash === str_repeat('b', 64);
        });

        self::assertTrue($verifier->verifyInvoice($invoice));
        self::assertTrue($verifier->verify(['artifact_hash' => str_repeat('b', 64), 'kms_signature' => 'proposal-signature']));
        self::assertSame([
            [$invoice['signature_payload_hash'], 'invoice-signature'],
            [str_repeat('b', 64), 'proposal-signature'],
        ], $calls);
    }

    public function test_transplanted_invoice_artifact_signature_is_rejected(): void
    {
        $source = $this->invoice();
        $source['signature_payload_hash'] = InvoiceSignatureEnvelope::hash($source);
        $verifier = new ArtifactVerifier(static fn (string $hash, string $signature): bool => $hash === $source['signature_payload_hash']);

        self::assertTrue($verifier->verifyInvoice($source));

        $target = $source;
        $target['public_id'] = str_repeat('e', 32);
        $target['invoice_number'] = 'E7-2026-4822';
        self::assertFalse($verifier->verifyInvoice($target));
    }

    public function test_plugin_routes_and_admin_download_keep_invoice_artifacts_behind_their_access_contracts(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/src/WordPress/PublicRoutes.php');
        $admin = (string) file_get_contents($root . '/src/WordPress/InvoiceAdmin.php');

        self::assertStringContainsString("'^invoice/verify/([a-f0-9]{32})/?$'", $routes);
        self::assertStringContainsString("'^invoice/download/([a-f0-9]{32})/?$'", $routes);
        self::assertStringContainsString("'e7_invoice_verify_id'", $routes);
        self::assertStringContainsString("'e7_invoice_download_id'", $routes);
        self::assertStringContainsString('InvoiceRoutePolicy::canCustomerDownload', $routes);
        self::assertStringContainsString("'invoice_download_url' =>", $routes);
        self::assertStringContainsString("admin_post_e7_invoice_download", $admin);
        self::assertStringContainsString("current_user_can(self::CAPABILITY)", $admin);
        self::assertStringContainsString("check_admin_referer('e7_invoice_download_'", $admin);
        self::assertStringContainsString("'/invoice/verify/'", $admin);
        self::assertStringNotContainsString('NFS-e', $admin);
    }

    public function test_invoice_verification_uses_the_theme_view_for_found_and_missing_records(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/PublicRoutes.php');
        $method = substr($routes, (int) strpos($routes, 'private function invoiceVerification'), 1200);

        self::assertStringContainsString("'screen' => 'invoice_verify'", $method);
        self::assertStringContainsString("'record' => \$record", $method);
        self::assertStringContainsString("'locale' => 'en_IE'", $method);
        self::assertStringContainsString("status_header(404)", $method);
        self::assertStringContainsString("\$this->render('invoice-verify.php')", $method);
        self::assertStringNotContainsString("echo '<!doctype html>", $method);
        self::assertStringNotContainsString('Invoice verification record was not found.', $method);
    }

    public function test_invoice_rewrite_rules_are_flushed_once_when_the_route_contract_changes(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Installer.php');

        self::assertStringContainsString('REWRITE_VERSION', $installer);
        self::assertStringContainsString("get_option('e7_propostas_rewrite_version')", $installer);
        self::assertStringContainsString("update_option('e7_propostas_rewrite_version'", $installer);
        self::assertStringContainsString('self::ensureRewrites()', $installer);
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return [
            'id' => 10,
            'version_id' => 5,
            'public_id' => str_repeat('f', 32),
            'snapshot_hash' => str_repeat('c', 64),
            'invoice_number' => 'E7-2026-4821',
            'issued_at' => '2026-07-20 12:00:00',
            'currency' => 'EUR',
            'total_minor' => 127500,
            'status' => 'issued',
            'artifact_key' => 'invoices/' . str_repeat('f', 32) . '.pdf#v1',
            'artifact_hash' => str_repeat('a', 64),
            'kms_signature' => 'invoice-signature',
            'supplier_profile' => ['legal_name' => 'E7 Company Tecnologia Ltda.', 'address' => 'secret supplier address'],
            'customer_profile' => [
                'legal_name' => 'Ross Motorcycles Limited',
                'registered_address' => ['line1' => 'secret customer address'],
                'vat_number' => 'IE6388047V',
                'finance_email' => 'finance@example.ie',
                'responsible' => ['phone' => '+353871234567'],
            ],
            'items' => [['description' => 'Secret service', 'amount_minor' => 127500]],
            'replacement_status' => null,
            'replacement_invoice_number' => null,
        ];
    }
}
