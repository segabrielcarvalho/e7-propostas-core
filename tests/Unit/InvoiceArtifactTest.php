<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\InvoiceFinalizer;
use E7Propostas\Infrastructure\InvoiceHtmlRenderer;
use E7Propostas\Infrastructure\InvoiceSignatureEnvelope;
use PHPUnit\Framework\TestCase;

final class InvoiceArtifactTest extends TestCase
{
    public function test_editorial_invoice_html_escapes_snapshots_and_contains_the_required_tax_wording(): void
    {
        $invoice = $this->invoice();
        $invoice['customer_profile']['legal_name'] = 'Ross <script>alert(1)</script> & Sons';
        $invoice['items'][0]['description'] = 'Website <img src=x onerror=alert(1)> & support';

        $html = (new InvoiceHtmlRenderer())->render($invoice, 'https://example.test/invoice/verify/' . $invoice['public_id'] . '/');

        self::assertStringContainsString('<title>Invoice E7-2026-4821</title>', $html);
        self::assertStringContainsString('INVOICE', $html);
        self::assertStringContainsString('Issue date', $html);
        self::assertStringContainsString('Due on receipt', $html);
        self::assertStringContainsString('Qty', $html);
        self::assertStringContainsString('Unit price', $html);
        self::assertStringContainsString('Line total', $html);
        self::assertStringContainsString('>1</td>', $html);
        self::assertStringContainsString('Total EUR', $html);
        self::assertStringContainsString('VAT not charged by the Supplier.', $html);
        self::assertStringContainsString('Reverse charge applies. Customer to account for Irish VAT.', $html);
        self::assertStringContainsString('Services supplied for the Customer’s business activities in Ireland.', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringContainsString('data:image/svg+xml;base64,', $html);
        self::assertStringContainsString('/invoice/verify/' . $invoice['public_id'] . '/', $html);
        self::assertStringContainsString($invoice['snapshot_hash'], $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('Ross &lt;script&gt;alert(1)&lt;/script&gt; &amp; Sons', $html);
        self::assertStringNotContainsString('VAT 0', $html);
        self::assertStringNotContainsString('VAT number', $html);
    }

    public function test_editorial_invoice_formats_minor_units_without_floating_point_precision_loss(): void
    {
        $invoice = $this->invoice();
        $invoice['total_minor'] = PHP_INT_MAX;
        $invoice['items'] = [['description' => 'Precision-safe service', 'amount_minor' => PHP_INT_MAX]];

        $html = (new InvoiceHtmlRenderer())->render($invoice, 'https://example.test/invoice/verify/' . $invoice['public_id'] . '/');

        self::assertStringContainsString('€92,233,720,368,547,758.07', $html);
    }

    public function test_complete_artifact_is_reused_without_rendering_signing_or_storing_again(): void
    {
        $invoice = $this->invoice() + [
            'artifact_key' => 'invoices/' . str_repeat('a', 32) . '.pdf#v1',
            'artifact_hash' => str_repeat('b', 64),
            'kms_signature' => 'signed',
            'issued_at' => '2026-07-20 12:00:00',
        ];
        $invoice['signature_payload_hash'] = InvoiceSignatureEnvelope::hash($invoice);
        $effects = 0;
        $finalizer = new InvoiceFinalizer(
            environment: static fn (): string => 'production',
            pdfRenderer: static function () use (&$effects): string { $effects++; return '%PDF-test'; },
            signer: static function () use (&$effects): string { $effects++; return 'signed'; },
            objectWriter: static function () use (&$effects): string { $effects++; return 'unused'; },
        );

        $artifact = $finalizer->finalize($invoice);

        self::assertSame($invoice['artifact_key'], $artifact['artifact_key']);
        self::assertSame($invoice['artifact_hash'], $artifact['artifact_hash']);
        self::assertSame('signed', $artifact['kms_signature']);
        self::assertSame($invoice['signature_payload_hash'], $artifact['signature_payload_hash']);
        self::assertSame(0, $effects);
    }

    public function test_partial_artifact_fails_before_any_external_effect(): void
    {
        $invoice = $this->invoice() + ['artifact_key' => 'invoices/partial.pdf', 'artifact_hash' => null, 'kms_signature' => null];
        $effects = 0;
        $finalizer = new InvoiceFinalizer(
            environment: static fn (): string => 'production',
            pdfRenderer: static function () use (&$effects): string { $effects++; return '%PDF-test'; },
            signer: static function () use (&$effects): string { $effects++; return 'signed'; },
            objectWriter: static function () use (&$effects): string { $effects++; return 'unused'; },
        );

        try {
            $finalizer->finalize($invoice);
            self::fail('A partial artifact must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('partial', strtolower($error->getMessage()));
        }

        self::assertSame(0, $effects);
    }

    public function test_local_finalization_writes_private_html_and_hash_without_claiming_a_kms_signature(): void
    {
        $written = [];
        $finalizer = new InvoiceFinalizer(
            environment: static fn (): string => 'local',
            localWriter: static function (string $publicId, string $html) use (&$written): string {
                $written = compact('publicId', 'html');
                return '/private/invoice-' . $publicId . '.html';
            },
        );

        $artifact = $finalizer->finalize($this->invoice());

        self::assertSame('/private/invoice-' . str_repeat('a', 32) . '.html', $artifact['artifact_key']);
        self::assertSame(hash('sha256', $written['html']), $artifact['artifact_hash']);
        self::assertNull($artifact['kms_signature']);
        self::assertSame('2026-07-20 12:00:00', $artifact['issued_at']);
        self::assertSame(
            InvoiceSignatureEnvelope::hash($this->invoice() + ['artifact_hash' => $artifact['artifact_hash'], 'issued_at' => $artifact['issued_at']]),
            $artifact['signature_payload_hash'],
        );
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return [
            'id' => 10,
            'public_id' => str_repeat('a', 32),
            'invoice_number' => 'E7-2026-4821',
            'currency' => 'EUR',
            'total_minor' => 127500,
            'status' => 'processing',
            'due_at' => '2026-07-20 12:00:00',
            'snapshot_hash' => str_repeat('c', 64),
            'supplier_profile' => [
                'legal_name' => 'E7 Company Tecnologia Ltda.',
                'tax_id' => '63.058.279/0001-84',
                'address' => 'Avenida Alvorada, 790, Apto 1508A',
                'district' => 'Chácaras Americanas',
                'city_state' => 'Anápolis/GO',
                'postal_code' => '75103-237',
                'country' => 'Brazil',
            ],
            'customer_profile' => [
                'legal_name' => 'Ross Motorcycles Limited',
                'trading_name' => 'Ross Motorcycles',
                'registration_number' => '123456',
                'registered_address' => [
                    'line1' => '1 Main Street', 'line2' => '', 'city' => 'Cork',
                    'county' => 'Cork', 'eircode' => 'T12 X4P5', 'country_code' => 'IE',
                ],
            ],
            'items' => [
                ['description' => 'Website implementation', 'amount_minor' => 125000],
                ['description' => 'Support', 'amount_minor' => 2500],
            ],
        ];
    }
}
