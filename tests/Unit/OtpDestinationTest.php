<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\OtpDestination;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtpDestinationTest extends TestCase
{
    public function test_accepts_an_email_destination(): void
    {
        $destination = OtpDestination::from('email', ' signer@example.com ');

        self::assertSame('email', $destination->channel);
        self::assertSame('signer@example.com', $destination->value);
    }

    public function test_accepts_an_e164_sms_destination(): void
    {
        $destination = OtpDestination::from('sms', '+353871234567');

        self::assertSame('sms', $destination->channel);
        self::assertSame('+353871234567', $destination->value);
    }

    #[DataProvider('invalidDestinations')]
    public function test_rejects_invalid_channels_and_destinations(string $channel, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        OtpDestination::from($channel, $value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDestinations(): iterable
    {
        yield 'unsupported channel' => ['both', 'signer@example.com'];
        yield 'invalid email' => ['email', 'not-an-email'];
        yield 'national phone' => ['sms', '11999999999'];
        yield 'too long phone' => ['sms', '+1234567890123456'];
    }
}
