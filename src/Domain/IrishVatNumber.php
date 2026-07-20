<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class IrishVatNumber
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            throw new \InvalidArgumentException('Irish VAT number must be text.');
        }
        $vat = strtoupper((string) preg_replace('/[^A-Z0-9*+]/i', '', trim((string) $value)));
        if ($vat === '') {
            return null;
        }
        if (! str_starts_with($vat, 'IE')) {
            $vat = 'IE' . $vat;
        }
        if (! preg_match('/^IE(?:[0-9]{7}[A-Z]{1,2}|[0-9][A-Z*+][0-9]{5}[A-Z])$/', $vat)) {
            throw new \InvalidArgumentException('Irish VAT number has an invalid format.');
        }
        return $vat;
    }

    public static function domesticPart(string $normalized): string
    {
        $vat = self::normalize($normalized);
        if ($vat === null) {
            throw new \InvalidArgumentException('Irish VAT number is absent.');
        }
        return substr($vat, 2);
    }
}
