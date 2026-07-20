<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceItems
{
    private const MAX_ITEMS = 100;
    private const MAX_DESCRIPTION_LENGTH = 500;

    /** @param mixed $input @return list<array{description: string, amount_minor: int}> */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input) || count($input) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('Invoice items must be an array with at most 100 entries.');
        }

        $normalized = [];
        foreach ($input as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Each invoice item must be an object.');
            }
            $description = self::text($item['description'] ?? '');
            $amount = $item['amount_minor'] ?? null;
            if ($description === '' && ($amount === '' || $amount === null)) {
                continue;
            }
            if ($description === '' || self::length($description) > self::MAX_DESCRIPTION_LENGTH) {
                throw new \InvalidArgumentException('Each invoice item needs a description of at most 500 characters.');
            }
            if (! is_int($amount) || $amount < 1) {
                throw new \InvalidArgumentException('Each invoice amount_minor must be a positive integer.');
            }
            $normalized[] = ['description' => $description, 'amount_minor' => $amount];
        }

        self::total($normalized);
        return $normalized;
    }

    /** @param list<array{description: string, amount_minor: int}> $items */
    public static function total(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $amount = $item['amount_minor'];
            if ($amount > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('Invoice total exceeds the supported integer range.');
            }
            $total += $amount;
        }
        return $total;
    }

    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
