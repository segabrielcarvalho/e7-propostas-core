<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class InvoiceHtmlRenderer
{
    /** @param array<string, mixed> $invoice */
    public function render(array $invoice, string $verificationUrl): string
    {
        $publicId = (string) ($invoice['public_id'] ?? '');
        $number = (string) ($invoice['invoice_number'] ?? '');
        $currency = (string) ($invoice['currency'] ?? '');
        $total = (int) ($invoice['total_minor'] ?? 0);
        $snapshotHash = (string) ($invoice['snapshot_hash'] ?? '');
        $supplier = is_array($invoice['supplier_profile'] ?? null) ? $invoice['supplier_profile'] : [];
        $customer = is_array($invoice['customer_profile'] ?? null) ? $invoice['customer_profile'] : [];
        $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];
        if (! preg_match('/^[a-f0-9]{32}$/', $publicId) || $number === '' || $currency !== 'EUR' || $total < 1 || ! preg_match('/^[a-f0-9]{64}$/', $snapshotHash) || $items === []) {
            throw new \InvalidArgumentException('Invoice document snapshot is incomplete.');
        }

        $rows = '';
        $calculatedTotal = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Invoice item snapshot is invalid.');
            }
            $description = (string) ($item['description'] ?? '');
            $amount = (int) ($item['amount_minor'] ?? 0);
            if ($description === '' || $amount < 1) {
                throw new \InvalidArgumentException('Invoice item snapshot is incomplete.');
            }
            $calculatedTotal += $amount;
            $rows .= '<tr><td>' . self::escape($description) . '</td><td class="number">1</td><td class="number">' . self::money($amount) . '</td><td class="number">' . self::money($amount) . '</td></tr>';
        }
        if ($calculatedTotal !== $total) {
            throw new \InvalidArgumentException('Invoice item total does not match its immutable snapshot.');
        }

        $issueDate = self::date((string) ($invoice['due_at'] ?? ''));
        $logo = self::logoDataUri();
        $qr = self::qrDataUri($verificationUrl);
        $supplierAddress = array_filter([
            (string) ($supplier['address'] ?? ''),
            (string) ($supplier['district'] ?? ''),
            (string) ($supplier['city_state'] ?? ''),
            (string) ($supplier['postal_code'] ?? ''),
            (string) ($supplier['country'] ?? ''),
        ], static fn (string $value): bool => $value !== '');
        $customerAddress = is_array($customer['registered_address'] ?? null) ? $customer['registered_address'] : [];
        $customerLines = array_filter([
            (string) ($customerAddress['line1'] ?? ''),
            (string) ($customerAddress['line2'] ?? ''),
            implode(', ', array_filter([(string) ($customerAddress['city'] ?? ''), (string) ($customerAddress['county'] ?? '')])),
            (string) ($customerAddress['eircode'] ?? ''),
            (string) ($customerAddress['country_code'] ?? ''),
        ], static fn (string $value): bool => $value !== '');

        return '<!doctype html><html lang="en-IE"><head><meta charset="utf-8"><title>Invoice ' . self::escape($number) . '</title><style>'
            . '@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;font:13px/1.5 Arial,sans-serif;color:#122033;background:#fff}.navy{height:18px;background:#071a33}.page{min-height:279mm;padding:15mm 17mm 13mm;display:flex;flex-direction:column}.mast{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.symbol{width:88px;height:auto;display:block}.title{text-align:right}.title h1{font:700 34px/1 Arial,sans-serif;letter-spacing:4px;margin:0 0 12px;color:#071a33}.meta{display:grid;grid-template-columns:auto auto;gap:4px 18px;text-align:left}.meta dt{color:#667085}.meta dd{margin:0;font-weight:700}.parties{display:grid;grid-template-columns:1fr 1fr;gap:44px;margin:30px 0}.label{font-size:10px;letter-spacing:1.8px;text-transform:uppercase;color:#667085;margin-bottom:7px}.legal{font-size:16px;font-weight:700;color:#071a33}.muted{color:#526071}table{width:100%;border-collapse:collapse}th{background:#071a33;color:#fff;font-size:10px;letter-spacing:1px;text-transform:uppercase}th,td{padding:10px 9px;text-align:left;border-bottom:1px solid #d8dee8}.number{text-align:right;white-space:nowrap}.total{margin:18px 0 28px auto;width:45%;display:flex;justify-content:space-between;border-top:2px solid #071a33;border-bottom:2px solid #071a33;padding:12px 4px;font-size:18px;font-weight:700}.notes{border-left:4px solid #1da1c9;padding:4px 0 4px 14px}.notes p{margin:4px 0}.footer{margin-top:auto;border-top:1px solid #d8dee8;padding-top:13px;display:grid;grid-template-columns:1fr 82px;gap:18px;align-items:end;font-size:9px;color:#526071}.footer code,.footer a{word-break:break-all;color:#071a33}.qr{width:82px;height:82px}</style></head><body><div class="navy"></div><main class="page">'
            . '<header class="mast"><img class="symbol" src="' . $logo . '" alt="E7"><section class="title"><h1>INVOICE</h1><dl class="meta"><dt>Number</dt><dd>' . self::escape($number) . '</dd><dt>Issue date</dt><dd>' . self::escape($issueDate) . '</dd><dt>Payment terms</dt><dd>Due on receipt</dd></dl></section></header>'
            . '<section class="parties"><div><div class="label">Supplier</div><div class="legal">' . self::escape((string) ($supplier['legal_name'] ?? '')) . '</div><div class="muted">' . self::escape((string) ($supplier['tax_id'] ?? '')) . '<br>' . implode('<br>', array_map(self::escape(...), $supplierAddress)) . '</div></div>'
            . '<div><div class="label">Customer</div><div class="legal">' . self::escape((string) ($customer['legal_name'] ?? '')) . '</div><div class="muted">' . self::escape((string) ($customer['trading_name'] ?? '')) . '<br>' . implode('<br>', array_map(self::escape(...), $customerLines)) . '</div></div></section>'
            . '<table><thead><tr><th>Services</th><th class="number">Qty</th><th class="number">Unit price</th><th class="number">Line total</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="total"><span>Total EUR</span><span>' . self::money($total) . '</span></div>'
            . '<section class="notes"><p>VAT not charged by the Supplier.</p><p>Reverse charge applies. Customer to account for Irish VAT.</p><p>Services supplied for the Customer’s business activities in Ireland.</p></section>'
            . '<footer class="footer"><div><strong>Immutable invoice snapshot SHA-256</strong><br><code>' . self::escape($snapshotHash) . '</code><br><strong>Verify</strong><br><a href="' . self::escape($verificationUrl) . '">' . self::escape($verificationUrl) . '</a></div><img class="qr" src="' . $qr . '" alt="Invoice verification QR code"></footer>'
            . '</main></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function money(int $minor): string
    {
        $major = (string) intdiv($minor, 100);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $major);
        if (! is_string($grouped)) {
            throw new \RuntimeException('Invoice amount could not be formatted.');
        }

        return '€' . $grouped . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function date(string $mysql): string
    {
        try {
            return (new \DateTimeImmutable($mysql, new \DateTimeZone('UTC')))->format('j F Y');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invoice issue date is invalid.');
        }
    }

    private static function logoDataUri(): string
    {
        $path = dirname(__DIR__, 2) . '/assets/brand/e7-symbol-transparent.png';
        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new \RuntimeException('E7 invoice symbol is unavailable.');
        }
        return 'data:image/png;base64,' . base64_encode($contents);
    }

    private static function qrDataUri(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invoice verification URL is invalid.');
        }
        $writer = new Writer(new ImageRenderer(new RendererStyle(180, 2), new SvgImageBackEnd()));
        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($url));
    }
}
