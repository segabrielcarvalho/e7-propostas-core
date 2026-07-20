<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\MoneyDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyDecimalTest extends TestCase
{
    #[DataProvider('validDecimals')]
    public function test_parses_readable_major_unit_values_without_floats(string $value, int $expected): void
    {
        self::assertSame($expected, MoneyDecimal::parse($value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function validDecimals(): iterable
    {
        yield 'whole euros' => ['1500', 150000];
        yield 'two decimal places' => ['1500.00', 150000];
        yield 'one decimal place' => ['12.5', 1250];
        yield 'local decimal comma' => ['1500,25', 150025];
        yield 'surrounding whitespace' => ['  0.01  ', 1];
    }

    #[DataProvider('invalidDecimals')]
    public function test_rejects_ambiguous_non_positive_or_unsupported_values(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MoneyDecimal::parse($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecimals(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1.00'];
        yield 'more than two decimals' => ['1.001'];
        yield 'ambiguous comma' => ['1,500'];
        yield 'mixed separators' => ['1,500.00'];
        yield 'scientific notation' => ['1e3'];
        yield 'overflow' => [(string) PHP_INT_MAX];
    }

    public function test_formats_minor_units_for_inputs_and_grouped_display_without_floats(): void
    {
        self::assertSame('1500.00', MoneyDecimal::formatInput(150000));
        self::assertSame('1,500.00', MoneyDecimal::formatDisplay(150000));
        self::assertSame('0.01', MoneyDecimal::formatInput(1));
    }
}
