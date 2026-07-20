<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceStatus
{
    public const DRAFT = 'draft';
    public const PROCESSING = 'processing';
    public const ISSUED = 'issued';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::DRAFT => [self::PROCESSING],
        self::PROCESSING => [self::ISSUED, self::FAILED],
        self::ISSUED => [self::CANCELLED],
        self::CANCELLED => [],
        self::FAILED => [self::PROCESSING],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new \DomainException(sprintf('Invoice cannot transition from %s to %s.', $from, $to));
        }
    }

    public static function assertValid(string $status): void
    {
        if (! array_key_exists($status, self::TRANSITIONS)) {
            throw new \InvalidArgumentException('Invalid invoice status.');
        }
    }
}
