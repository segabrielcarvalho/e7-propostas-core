<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceNumber
{
    public static function initialSequence(?callable $random = null): int
    {
        $random ??= static fn (int $min, int $max): int => random_int($min, $max);
        $value = $random(1000, 8999);
        if (! is_int($value) || $value < 1000 || $value > 8999) {
            throw new \RuntimeException('Invoice sequence source returned an invalid initial value.');
        }
        return $value;
    }

    public static function next(int $current): int
    {
        if ($current < 1000 || $current >= 9999) {
            throw new \OverflowException('The four-digit invoice sequence is exhausted.');
        }
        return $current + 1;
    }

    public static function format(int $year, int $sequence): string
    {
        if ($year < 2000 || $year > 9999 || $sequence < 1000 || $sequence > 9999) {
            throw new \InvalidArgumentException('Invoice year or sequence is invalid.');
        }
        return sprintf('E7-%04d-%04d', $year, $sequence);
    }
}
