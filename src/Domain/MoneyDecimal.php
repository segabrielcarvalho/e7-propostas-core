<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class MoneyDecimal
{
    public static function parse(string $value): int
    {
        $decimal = trim($value);
        if (! preg_match('/^([0-9]+)(?:([.,])([0-9]{1,2}))?$/', $decimal, $matches)) {
            throw new \InvalidArgumentException('Invoice amount must be a positive decimal with at most two decimal places.');
        }

        $majorText = ltrim($matches[1], '0');
        $majorText = $majorText === '' ? '0' : $majorText;
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $minorPart = (int) $fraction;
        $maxMajor = intdiv(PHP_INT_MAX, 100);
        $maxMajorText = (string) $maxMajor;
        if (strlen($majorText) > strlen($maxMajorText)
            || (strlen($majorText) === strlen($maxMajorText) && strcmp($majorText, $maxMajorText) > 0)
        ) {
            throw new \InvalidArgumentException('Invoice amount exceeds the supported integer range.');
        }

        $major = (int) $majorText;
        if ($major === $maxMajor && $minorPart > PHP_INT_MAX % 100) {
            throw new \InvalidArgumentException('Invoice amount exceeds the supported integer range.');
        }

        $minor = ($major * 100) + $minorPart;
        if ($minor < 1) {
            throw new \InvalidArgumentException('Invoice amount must be greater than zero.');
        }
        return $minor;
    }

    public static function formatInput(int $minor): string
    {
        self::assertNonNegative($minor);
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function formatDisplay(int $minor): string
    {
        self::assertNonNegative($minor);
        $major = (string) intdiv($minor, 100);
        $groups = str_split(strrev($major), 3);
        $grouped = strrev(implode(',', $groups));
        return $grouped . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function assertNonNegative(int $minor): void
    {
        if ($minor < 0) {
            throw new \InvalidArgumentException('Minor units cannot be negative.');
        }
    }
}
