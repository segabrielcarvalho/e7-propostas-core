<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

interface InvoiceStore
{
    /** @return array<string, mixed> */
    public function acceptanceContext(int $acceptanceId): array;
    /** @return array<string, mixed>|null */
    public function currentRoot(int $acceptanceId): ?array;
    /** @return array<string, mixed>|null */
    public function get(int $invoiceId): ?array;
    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function createDraft(array $snapshot): array;
    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function updateDraftCustomer(int $invoiceId, array $profile): array;
    /** @return array<string, mixed> */
    public function beginIssue(int $invoiceId): array;
    public function markFailed(int $invoiceId, string $message): void;
    /** @return array<string, mixed> */
    public function beginRetry(int $invoiceId): array;
    /** @return array<string, mixed> */
    public function cancel(int $invoiceId): array;
    /** @return array<string, mixed> */
    public function createReplacement(int $invoiceId): array;
    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function updateVies(int $invoiceId, array $result): array;
    public function enqueueFinalization(int $invoiceId): void;
    /** @param array<string, mixed> $payload */
    public function appendAudit(int $versionId, string $type, array $payload): void;
}
