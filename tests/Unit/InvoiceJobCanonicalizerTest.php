<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\InvoiceJobCanonicalizer;
use PHPUnit\Framework\TestCase;

final class InvoiceJobCanonicalizerTest extends TestCase
{
    public function test_pending_job_wins_over_failed_job_regardless_of_id_order(): void
    {
        $selection = InvoiceJobCanonicalizer::choose([
            $this->job(9, 'pending', '2026-07-19 12:00:00'),
            $this->job(10, 'failed', '2026-07-20 12:00:00'),
        ], 'processing');

        self::assertSame(9, $selection['canonical_id']);
        self::assertFalse($selection['rebuild']);
    }

    public function test_processing_job_has_priority_over_newer_pending_and_retry_jobs(): void
    {
        $selection = InvoiceJobCanonicalizer::choose([
            $this->job(1, 'processing', '2026-07-18 12:00:00'),
            $this->job(2, 'pending', '2026-07-20 12:00:00'),
            $this->job(3, 'retry', '2026-07-20 13:00:00'),
        ], 'processing');

        self::assertSame(1, $selection['canonical_id']);
        self::assertFalse($selection['rebuild']);
    }

    public function test_most_recent_job_wins_within_the_same_runnable_status(): void
    {
        $selection = InvoiceJobCanonicalizer::choose([
            $this->job(1, 'pending', '2026-07-20 12:00:00'),
            $this->job(2, 'pending', '2026-07-20 13:00:00'),
        ], 'processing');

        self::assertSame(2, $selection['canonical_id']);
    }

    public function test_processing_invoice_rebuilds_failed_job_as_pending(): void
    {
        $selection = InvoiceJobCanonicalizer::choose([
            $this->job(8, 'failed', '2026-07-20 12:00:00'),
        ], 'processing');

        self::assertSame(8, $selection['canonical_id']);
        self::assertTrue($selection['rebuild']);
    }

    public function test_processing_invoice_without_jobs_requests_idempotent_pending_rebuild(): void
    {
        $selection = InvoiceJobCanonicalizer::choose([], 'processing');

        self::assertNull($selection['canonical_id']);
        self::assertTrue($selection['rebuild']);
    }

    /** @return array{id: int, status: string, updated_at: string} */
    private function job(int $id, string $status, string $updatedAt): array
    {
        return ['id' => $id, 'status' => $status, 'updated_at' => $updatedAt];
    }
}
