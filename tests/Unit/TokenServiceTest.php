<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    public function test_generates_an_unguessable_hex_token(): void
    {
        $service = new TokenService('test-pepper');

        $first = $service->generate();
        $second = $service->generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function test_hashes_tokens_with_an_application_pepper(): void
    {
        $token = str_repeat('a', 64);

        $first = (new TokenService('pepper-one'))->hash($token);
        $second = (new TokenService('pepper-two'))->hash($token);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertNotSame($first, $second);
    }
}
