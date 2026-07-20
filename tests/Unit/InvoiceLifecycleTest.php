<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\InvoiceMoney;
use E7Propostas\Domain\InvoiceNumber;
use E7Propostas\Domain\InvoiceStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceLifecycleTest extends TestCase
{
    public function test_money_keeps_integer_minor_units_and_currency_when_adding(): void
    {
        $total = (new InvoiceMoney(125000, 'EUR'))->add(new InvoiceMoney(2500, 'EUR'));

        self::assertSame(127500, $total->minor());
        self::assertSame('EUR', $total->currency());
    }

    public function test_money_rejects_non_positive_values_and_mixed_currencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceMoney(100, 'EUR'))->add(new InvoiceMoney(100, 'BRL'));
    }

    public function test_first_number_uses_a_four_digit_random_base_and_subsequent_numbers_increment(): void
    {
        $base = InvoiceNumber::initialSequence(static fn (int $min, int $max): int => 4821);

        self::assertSame(4821, $base);
        self::assertSame('E7-2026-4821', InvoiceNumber::format(2026, $base));
        self::assertSame('E7-2026-4822', InvoiceNumber::format(2026, InvoiceNumber::next($base)));
    }

    public function test_number_rejects_values_outside_the_four_digit_yearly_range(): void
    {
        $this->expectException(\OverflowException::class);
        InvoiceNumber::next(9999);
    }

    #[DataProvider('allowedTransitions')]
    public function test_status_allows_only_the_invoice_lifecycle(string $from, string $to): void
    {
        self::assertTrue(InvoiceStatus::canTransition($from, $to));
    }

    /** @return iterable<string, array{string, string}> */
    public static function allowedTransitions(): iterable
    {
        yield 'issue reserves number' => ['draft', 'processing'];
        yield 'finalization succeeds' => ['processing', 'issued'];
        yield 'finalization fails' => ['processing', 'failed'];
        yield 'retry preserves number' => ['failed', 'processing'];
        yield 'issued invoice is cancelled' => ['issued', 'cancelled'];
    }

    public function test_status_rejects_skipping_processing_or_editing_terminal_states(): void
    {
        self::assertFalse(InvoiceStatus::canTransition('draft', 'issued'));
        self::assertFalse(InvoiceStatus::canTransition('cancelled', 'draft'));
        self::assertFalse(InvoiceStatus::canTransition('issued', 'processing'));
    }
}
