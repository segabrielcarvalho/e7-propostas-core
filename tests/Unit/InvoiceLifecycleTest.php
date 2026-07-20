<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\InvoiceMoney;
use E7Propostas\Domain\InvoiceNumber;
use E7Propostas\Domain\InvoiceSnapshot;
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
        $requestedRange = [];
        $base = InvoiceNumber::initialSequence(static function (int $min, int $max) use (&$requestedRange): int {
            $requestedRange = [$min, $max];
            return 4821;
        });

        self::assertSame([1000, 6999], $requestedRange);
        self::assertSame(4821, $base);
        self::assertSame('E7-2026-4821', InvoiceNumber::format(2026, $base));
        self::assertSame('E7-2026-4822', InvoiceNumber::format(2026, InvoiceNumber::next($base)));
    }

    public function test_initial_number_preserves_at_least_3001_numbers_of_yearly_capacity(): void
    {
        $this->expectException(\RuntimeException::class);
        InvoiceNumber::initialSequence(static fn (int $min, int $max): int => 7000);
    }

    public function test_snapshot_hash_binds_identity_totals_and_normalized_snapshots(): void
    {
        $customer = $this->customerProfile();
        $supplier = ['legal_name' => 'E7 Company Tecnologia Ltda.', 'tax_id' => '63.058.279/0001-84', 'address' => 'Avenida Alvorada, 790, Apto 1508A', 'district' => 'Chácaras Americanas', 'city_state' => 'Anápolis/GO', 'postal_code' => '75103-237', 'country' => 'Brazil'];
        $items = [['description' => 'Service', 'amount_minor' => 10000]];

        $hash = InvoiceSnapshot::hash(str_repeat('a', 32), 41, 5, 'EUR', 10000, $customer, $supplier, $items);

        self::assertSame($hash, InvoiceSnapshot::hash(str_repeat('a', 32), 41, 5, 'EUR', 10000, $customer, $supplier, $items));
        self::assertNotSame($hash, InvoiceSnapshot::hash(str_repeat('b', 32), 41, 5, 'EUR', 10000, $customer, $supplier, $items));
        self::assertNotSame($hash, InvoiceSnapshot::hash(str_repeat('a', 32), 42, 5, 'EUR', 10000, $customer, $supplier, $items));
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

    /** @return array<string, mixed> */
    private function customerProfile(): array
    {
        return [
            'responsible' => ['name' => 'Aoife Murphy', 'role' => 'Director', 'email' => 'aoife@example.ie', 'phone' => '+353871234567'],
            'type' => 'company', 'legal_name' => 'Ross Motorcycles Limited', 'trading_name' => 'Ross Motorcycles',
            'registration_number' => '123456', 'vat_registered' => true, 'vat_number' => 'IE6388047V',
            'registered_address' => ['line1' => '1 Main Street', 'line2' => '', 'city' => 'Cork', 'county' => 'Cork', 'eircode' => 'T12 X4P5', 'country_code' => 'IE'],
            'billing_same_as_registered' => true, 'billing_address' => [], 'payer_same_as_business' => true, 'payer_legal_name' => '',
            'finance_email' => 'finance@example.ie', 'purchase_order' => '', 'service_city' => 'Cork', 'domain' => 'rossmotorcycles.ie', 'whatsapp' => '',
            'confirmations' => ['b2b' => true, 'ireland' => true, 'accuracy' => true],
        ];
    }
}
