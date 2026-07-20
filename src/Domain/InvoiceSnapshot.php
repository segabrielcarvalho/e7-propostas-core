<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceSnapshot
{
    /** @param array<string, mixed> $customer @param array<string, mixed> $supplier @param list<array<string, mixed>> $items */
    public static function hash(string $publicId, int $acceptanceId, int $versionId, string $currency, int $totalMinor, array $customer, array $supplier, array $items): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $publicId) || $acceptanceId < 1 || $versionId < 1) {
            throw new \InvalidArgumentException('Invoice snapshot identity is invalid.');
        }
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invoice snapshot currency is invalid.');
        }
        $normalizedCustomer = BusinessProfile::normalize($customer);
        $normalizedSupplier = SupplierProfile::normalize($supplier);
        $normalizedItems = InvoiceItems::normalize($items);
        if ($normalizedItems === [] || InvoiceItems::total($normalizedItems) !== $totalMinor) {
            throw new \InvalidArgumentException('Invoice snapshot total does not match its items.');
        }
        return CanonicalPayload::hash([
            'public_id' => $publicId,
            'acceptance_id' => $acceptanceId,
            'version_id' => $versionId,
            'currency' => $currency,
            'total_minor' => $totalMinor,
            'customer' => $normalizedCustomer,
            'supplier' => $normalizedSupplier,
            'items' => $normalizedItems,
        ]);
    }
}
