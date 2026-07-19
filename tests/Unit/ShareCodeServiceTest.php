<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\ShareCodeService;
use PHPUnit\Framework\TestCase;

final class ShareCodeServiceTest extends TestCase
{
    public function test_generates_an_eight_character_unambiguous_code(): void
    {
        $service = new ShareCodeService();

        $first = $service->generate();
        $second = $service->generate();

        self::assertMatchesRegularExpression('/^[a-hj-km-np-z2-9]{8}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function test_normalizes_case_and_rejects_ambiguous_or_malformed_codes(): void
    {
        $service = new ShareCodeService();

        self::assertSame('k7m3x9qa', $service->normalize('K7M3X9QA'));
        self::assertNull($service->normalize('k7m3x9q'));
        self::assertNull($service->normalize('k7m3x9q0'));
        self::assertNull($service->normalize('k7m3x9ql'));
    }

    public function test_retries_collisions_before_returning_a_unique_code(): void
    {
        $index = 0;
        $random = static function (int $maximum) use (&$index): int {
            $batch = intdiv($index++, 8);
            return min($batch, $maximum);
        };
        $service = new ShareCodeService($random(...));

        $code = $service->generateUnique(static fn (string $candidate): bool => $candidate === 'aaaaaaaa');

        self::assertSame('bbbbbbbb', $code);
    }
}
