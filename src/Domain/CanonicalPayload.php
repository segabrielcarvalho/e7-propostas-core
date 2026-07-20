<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class CanonicalPayload
{
    /** @param array<string, mixed> $payload */
    public static function encode(array $payload): string
    {
        return json_encode(self::normalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($value !== [] && ! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
