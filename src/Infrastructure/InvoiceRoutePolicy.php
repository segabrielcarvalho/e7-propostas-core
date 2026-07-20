<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class InvoiceRoutePolicy
{
    /** @param array<string, mixed> $invoice @return array<string, bool|int|string|null> */
    public static function verificationRecord(array $invoice, bool $signatureVerified): array
    {
        $status = (string) ($invoice['status'] ?? '');
        if (! in_array($status, ['issued', 'cancelled'], true)) {
            throw new \DomainException('Only issued or cancelled invoices are publicly verifiable.');
        }
        $supplier = is_array($invoice['supplier_profile'] ?? null) ? $invoice['supplier_profile'] : [];
        $customer = is_array($invoice['customer_profile'] ?? null) ? $invoice['customer_profile'] : [];
        $replacementStatus = (string) ($invoice['replacement_status'] ?? '');
        return [
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'supplier_legal_name' => (string) ($supplier['legal_name'] ?? ''),
            'customer_legal_name' => (string) ($customer['legal_name'] ?? ''),
            'issued_at' => (string) ($invoice['issued_at'] ?? ''),
            'currency' => (string) ($invoice['currency'] ?? ''),
            'total' => number_format((int) ($invoice['total_minor'] ?? 0) / 100, 2, '.', ''),
            'status' => $status,
            'artifact_hash' => (string) ($invoice['artifact_hash'] ?? ''),
            'signature_verified' => $signatureVerified,
            'cancelled' => $status === 'cancelled',
            'replacement_status' => $replacementStatus !== '' ? $replacementStatus : null,
            'replacement_invoice_number' => $replacementStatus === 'issued' ? (string) ($invoice['replacement_invoice_number'] ?? '') : null,
        ];
    }

    /** @param array<string, mixed> $invoice @param array<string, mixed>|null $session */
    public static function canCustomerDownload(array $invoice, ?array $session): bool
    {
        return ($invoice['status'] ?? null) === 'issued'
            && is_array($session)
            && (int) ($session['version_id'] ?? 0) === (int) ($invoice['version_id'] ?? 0)
            && trim((string) ($invoice['artifact_key'] ?? '')) !== ''
            && preg_match('/^[a-f0-9]{64}$/', (string) ($invoice['artifact_hash'] ?? '')) === 1;
    }
}
