<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use E7Propostas\Domain\CanonicalPayload;

final class InvoiceSignatureEnvelope
{
    /** @param array<string, mixed> $invoice */
    public static function hash(array $invoice): string
    {
        $publicId = strtolower(trim((string) ($invoice['public_id'] ?? '')));
        $snapshotHash = strtolower(trim((string) ($invoice['snapshot_hash'] ?? '')));
        $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
        $issuedAt = trim((string) ($invoice['issued_at'] ?? ''));
        $artifactHash = strtolower(trim((string) ($invoice['artifact_hash'] ?? '')));
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
        $totalMinor = $invoice['total_minor'] ?? null;
        if (! preg_match('/^[a-f0-9]{32}$/', $publicId)
            || ! preg_match('/^[a-f0-9]{64}$/', $snapshotHash)
            || $invoiceNumber === ''
            || ! self::isMysqlUtc($issuedAt)
            || ! preg_match('/^[a-f0-9]{64}$/', $artifactHash)
            || ! preg_match('/^[A-Z]{3}$/', $currency)
            || ! is_int($totalMinor)
            || $totalMinor < 1) {
            throw new \InvalidArgumentException('Invoice signature envelope is incomplete.');
        }
        return CanonicalPayload::hash([
            'type' => 'e7-commercial-invoice',
            'version' => 1,
            'public_id' => $publicId,
            'snapshot_hash' => $snapshotHash,
            'invoice_number' => $invoiceNumber,
            'issued_at' => $issuedAt,
            'artifact_hash' => $artifactHash,
            'currency' => $currency,
            'total_minor' => $totalMinor,
        ]);
    }

    private static function isMysqlUtc(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
    }
}
