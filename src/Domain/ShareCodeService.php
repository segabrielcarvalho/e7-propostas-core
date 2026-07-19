<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class ShareCodeService
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const LENGTH = 8;

    public function __construct(private readonly ?\Closure $randomIndex = null)
    {
    }

    public function generate(): string
    {
        $code = '';
        $maximum = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < self::LENGTH; $position++) {
            $index = $this->randomIndex instanceof \Closure
                ? ($this->randomIndex)($maximum)
                : random_int(0, $maximum);
            $code .= self::ALPHABET[$index];
        }

        return $code;
    }

    public function normalize(string $code): ?string
    {
        $normalized = strtolower(trim($code));
        return preg_match('/^[a-hj-km-np-z2-9]{8}$/', $normalized) === 1 ? $normalized : null;
    }

    /** @param callable(string): bool $exists */
    public function generateUnique(callable $exists): string
    {
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $candidate = $this->generate();
            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique proposal share code.');
    }
}
