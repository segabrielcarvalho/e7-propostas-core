<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\WordPress\InvoiceService;
use E7Propostas\WordPress\InvoiceStore;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceTest extends TestCase
{
    public function test_prepare_freezes_modern_acceptance_customer_items_and_total(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $invoice = (new InvoiceService($store))->prepareDraft(41, null, null, false, 7);

        self::assertSame('draft', $invoice['status']);
        self::assertSame('EUR', $invoice['currency']);
        self::assertSame(127500, $invoice['total_minor']);
        self::assertSame($this->context()['invoice_items'], $invoice['items']);
        self::assertSame('Ross Motorcycles Limited', $invoice['customer_profile']['legal_name']);
    }

    public function test_legacy_backfill_requires_explicit_confirmation_and_is_audited(): void
    {
        $context = $this->context();
        $context['customer_profile'] = null;
        $context['invoice_items'] = [];
        $store = new InMemoryInvoiceStore($context);
        $service = new InvoiceService($store);

        try {
            $service->prepareDraft(41, $this->profile(), $this->items(), false, 7);
            self::fail('Legacy backfill should require explicit confirmation.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('confirmation', $error->getMessage());
        }

        $service->prepareDraft(41, $this->profile(), $this->items(), true, 7);
        self::assertSame('invoice.legacy_backfill_confirmed', $store->audits[0]['type']);
        self::assertSame(7, $store->audits[0]['payload']['actor_id']);
    }

    public function test_issue_requires_audited_acknowledgement_for_non_valid_vies_and_enqueues_once(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $service = new InvoiceService($store);
        $invoice = $service->prepareDraft(41, null, null, false, 7);

        $this->expectException(\DomainException::class);
        try {
            $service->issue((int) $invoice['id'], false, 7);
        } finally {
            self::assertSame([], $store->jobs);
        }
    }

    public function test_issue_acknowledgement_is_audited_and_failure_keeps_the_reserved_number(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $service = new InvoiceService($store);
        $invoice = $service->prepareDraft(41, null, null, false, 7);
        $store->failEnqueue = true;

        $this->expectException(\RuntimeException::class);
        try {
            $service->issue((int) $invoice['id'], true, 7);
        } finally {
            self::assertSame('E7-2026-4821', $store->invoice['invoice_number']);
            self::assertSame('failed', $store->invoice['status']);
            self::assertContains('invoice.vies_acknowledged', array_column($store->audits, 'type'));
        }
    }

    public function test_retry_failed_invoice_reuses_the_reserved_number(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $store->invoice = $store->createDraft([
            'acceptance_id' => 41,
            'version_id' => 5,
            'currency' => 'EUR',
            'customer_profile' => $this->profile(),
            'supplier_profile' => [],
            'items' => $this->items(),
            'total_minor' => 127500,
        ]);
        $store->invoice['status'] = 'failed';
        $store->invoice['invoice_number'] = 'E7-2026-4821';

        (new InvoiceService($store))->retry((int) $store->invoice['id'], 7);

        self::assertSame('processing', $store->invoice['status']);
        self::assertSame('E7-2026-4821', $store->invoice['invoice_number']);
        self::assertCount(1, $store->jobs);
    }

    public function test_only_customer_profile_can_be_corrected_while_draft(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $service = new InvoiceService($store);
        $invoice = $service->prepareDraft(41, null, null, false, 7);
        $corrected = $this->profile();
        $corrected['finance_email'] = 'billing@example.ie';

        $updated = $service->saveDraftCustomer((int) $invoice['id'], $corrected, 7);

        self::assertSame('billing@example.ie', $updated['customer_profile']['finance_email']);
        self::assertSame(127500, $updated['total_minor']);
        self::assertSame($this->items(), $updated['items']);
    }

    public function test_recheck_vies_persists_minimal_result_and_audits_it(): void
    {
        $store = new InMemoryInvoiceStore($this->context());
        $service = new InvoiceService($store, static fn (string $vat): array => [
            'status' => 'valid',
            'checked_at' => '2026-07-20 12:00:00',
            'evidence' => ['country_code' => 'IE', 'response_hash' => str_repeat('a', 64)],
        ]);
        $invoice = $service->prepareDraft(41, null, null, false, 7);

        $updated = $service->recheckVies((int) $invoice['id'], 7);

        self::assertSame('valid', $updated['vies_status']);
        self::assertContains('invoice.vies_checked', array_column($store->audits, 'type'));
        $checked = array_values(array_filter($store->audits, static fn (array $audit): bool => $audit['type'] === 'invoice.vies_checked'))[0];
        self::assertArrayNotHasKey('vat_number', $checked['payload']);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'acceptance_id' => 41,
            'version_id' => 5,
            'locale' => 'en_IE',
            'currency' => 'EUR',
            'customer_profile' => $this->profile(),
            'invoice_items' => $this->items(),
            'invoice_total_minor' => 127500,
        ];
    }

    /** @return list<array{description: string, amount_minor: int}> */
    private function items(): array
    {
        return [
            ['description' => 'Website implementation', 'amount_minor' => 125000],
            ['description' => 'Support', 'amount_minor' => 2500],
        ];
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return [
            'responsible' => ['name' => 'Aoife Murphy', 'role' => 'Director', 'email' => 'aoife@example.ie', 'phone' => '+353871234567'],
            'type' => 'company',
            'legal_name' => 'Ross Motorcycles Limited',
            'trading_name' => 'Ross Motorcycles',
            'registration_number' => '123456',
            'vat_registered' => true,
            'vat_number' => 'IE6388047V',
            'registered_address' => ['line1' => '1 Main Street', 'line2' => '', 'city' => 'Cork', 'county' => 'Cork', 'eircode' => 'T12 X4P5', 'country_code' => 'IE'],
            'billing_same_as_registered' => true,
            'billing_address' => [],
            'payer_same_as_business' => true,
            'payer_legal_name' => '',
            'finance_email' => 'finance@example.ie',
            'purchase_order' => '',
            'service_city' => 'Cork',
            'domain' => 'rossmotorcycles.ie',
            'whatsapp' => '',
            'confirmations' => ['b2b' => true, 'ireland' => true, 'accuracy' => true],
        ];
    }
}

final class InMemoryInvoiceStore implements InvoiceStore
{
    /** @var array<string, mixed>|null */
    public ?array $invoice = null;
    /** @var list<array<string, mixed>> */
    public array $audits = [];
    /** @var list<int> */
    public array $jobs = [];
    public bool $failEnqueue = false;

    /** @param array<string, mixed> $context */
    public function __construct(private array $context)
    {
    }

    public function acceptanceContext(int $acceptanceId): array { return $this->context; }
    public function currentRoot(int $acceptanceId): ?array { return $this->invoice; }
    public function get(int $invoiceId): ?array { return $this->invoice; }
    public function createDraft(array $snapshot): array
    {
        return $this->invoice = $snapshot + ['id' => 10, 'status' => 'draft', 'invoice_number' => null, 'vies_status' => 'not_requested'];
    }
    public function updateDraftCustomer(int $invoiceId, array $profile): array
    {
        $this->invoice['customer_profile'] = $profile;
        return $this->invoice;
    }
    public function beginIssue(int $invoiceId): array
    {
        $this->invoice['status'] = 'processing';
        $this->invoice['invoice_number'] ??= 'E7-2026-4821';
        return $this->invoice;
    }
    public function markFailed(int $invoiceId, string $message): void { $this->invoice['status'] = 'failed'; }
    public function beginRetry(int $invoiceId): array { $this->invoice['status'] = 'processing'; return $this->invoice; }
    public function cancel(int $invoiceId): array { $this->invoice['status'] = 'cancelled'; return $this->invoice; }
    public function createReplacement(int $invoiceId): array { return $this->invoice; }
    public function updateVies(int $invoiceId, array $result): array { return $this->invoice = array_merge($this->invoice, ['vies_status' => $result['status']], $result); }
    public function enqueueFinalization(int $invoiceId): void
    {
        if ($this->failEnqueue) { throw new \RuntimeException('queue unavailable'); }
        $this->jobs[] = $invoiceId;
    }
    public function appendAudit(int $versionId, string $type, array $payload): void { $this->audits[] = compact('type', 'payload'); }
}
