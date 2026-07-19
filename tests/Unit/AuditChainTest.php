<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\AuditChain;
use PHPUnit\Framework\TestCase;

final class AuditChainTest extends TestCase
{
    public function test_chains_events_so_tampering_changes_all_following_hashes(): void
    {
        $chain = new AuditChain();
        $opened = ['type' => 'proposal.opened', 'occurred_at' => '2026-07-18T12:00:00Z'];
        $accepted = ['type' => 'proposal.accepted', 'occurred_at' => '2026-07-18T12:05:00Z'];

        $firstHash = $chain->next(null, $opened);
        $secondHash = $chain->next($firstHash, $accepted);
        $tamperedHash = $chain->next($chain->next(null, [...$opened, 'occurred_at' => '2026-07-18T12:01:00Z']), $accepted);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secondHash);
        self::assertNotSame($secondHash, $tamperedHash);
    }
}
