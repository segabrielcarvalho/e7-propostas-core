<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class ArtifactJobDispatcher
{
    private readonly \Closure $acceptanceHandler;
    private readonly \Closure $invoiceHandler;
    private readonly \Closure $invoiceFailureHandler;

    public function __construct(callable $acceptanceHandler, callable $invoiceHandler, callable $invoiceFailureHandler)
    {
        $this->acceptanceHandler = \Closure::fromCallable($acceptanceHandler);
        $this->invoiceHandler = \Closure::fromCallable($invoiceHandler);
        $this->invoiceFailureHandler = \Closure::fromCallable($invoiceFailureHandler);
    }

    /** @param array<string, mixed> $job */
    public function dispatch(array $job): ?string
    {
        $type = (string) ($job['job_type'] ?? '');
        $payload = $this->payload($job);
        return match ($type) {
            'finalize_acceptance' => ($this->acceptanceHandler)($job, $payload),
            'finalize_invoice' => ($this->invoiceHandler)($job, $this->invoicePayload($payload)),
            default => throw new \DomainException('Unknown artifact job type: ' . $type),
        };
    }

    /** @param array<string, mixed> $job */
    public function recordFailure(array $job, \Throwable $error, bool $terminal): void
    {
        if (! $terminal || ($job['job_type'] ?? null) !== 'finalize_invoice') {
            return;
        }
        $payload = $this->payload($job);
        $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($invoiceId)) {
            return;
        }
        ($this->invoiceFailureHandler)($invoiceId, $error->getMessage());
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function payload(array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \DomainException('Artifact job payload must be an object.');
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload @return array{invoice_id: int, public_id: string} */
    private function invoicePayload(array $payload): array
    {
        $invoiceId = filter_var($payload['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $publicId = strtolower((string) ($payload['public_id'] ?? ''));
        if (! is_int($invoiceId) || ! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            throw new \DomainException('Invoice artifact job payload is invalid.');
        }
        return ['invoice_id' => $invoiceId, 'public_id' => $publicId];
    }
}
