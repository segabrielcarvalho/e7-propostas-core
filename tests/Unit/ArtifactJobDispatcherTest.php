<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\ArtifactJobDispatcher;
use PHPUnit\Framework\TestCase;

final class ArtifactJobDispatcherTest extends TestCase
{
    public function test_finalize_invoice_is_dispatched_only_to_the_invoice_handler(): void
    {
        $calls = [];
        $dispatcher = new ArtifactJobDispatcher(
            static function () use (&$calls): string { $calls[] = 'acceptance'; return 'acceptance'; },
            static function (array $job, array $payload) use (&$calls): string {
                $calls[] = ['invoice', $job['id'], $payload['invoice_id'], $payload['public_id']];
                return 'invoice';
            },
            static function (): void {},
        );

        $result = $dispatcher->dispatch($this->job('finalize_invoice'));

        self::assertSame('invoice', $result);
        self::assertSame([['invoice', 9, 41, str_repeat('a', 32)]], $calls);
    }

    public function test_unknown_job_is_never_processed_as_an_acceptance(): void
    {
        $acceptances = 0;
        $dispatcher = new ArtifactJobDispatcher(
            static function () use (&$acceptances): void { $acceptances++; },
            static function (): void {},
            static function (): void {},
        );

        try {
            $dispatcher->dispatch($this->job('unknown_job'));
            self::fail('Unknown jobs must be rejected.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Unknown artifact job type', $error->getMessage());
        }

        self::assertSame(0, $acceptances);
    }

    public function test_only_terminal_invoice_failure_updates_the_invoice_contract(): void
    {
        $failures = [];
        $dispatcher = new ArtifactJobDispatcher(
            static function (): void {},
            static function (): void {},
            static function (int $invoiceId, string $message) use (&$failures): void { $failures[] = [$invoiceId, $message]; },
        );
        $job = $this->job('finalize_invoice');

        $dispatcher->recordFailure($job, new \RuntimeException('renderer unavailable'), false);
        $dispatcher->recordFailure($job, new \RuntimeException('renderer unavailable'), true);

        self::assertSame([[41, 'renderer unavailable']], $failures);
    }

    /** @return array<string, mixed> */
    private function job(string $type): array
    {
        return [
            'id' => 9,
            'job_type' => $type,
            'payload' => json_encode(['invoice_id' => 41, 'public_id' => str_repeat('a', 32)], JSON_THROW_ON_ERROR),
        ];
    }
}
