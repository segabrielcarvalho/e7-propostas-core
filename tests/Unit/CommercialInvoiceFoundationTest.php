<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\BusinessProfile;
use E7Propostas\Domain\InvoiceItems;
use E7Propostas\Infrastructure\FeatureFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommercialInvoiceFoundationTest extends TestCase
{
    public function test_feature_flags_are_enabled_by_default_and_accept_explicit_safe_disable(): void
    {
        self::assertTrue(class_exists(FeatureFlags::class), 'FeatureFlags must exist.');

        $defaults = new FeatureFlags([]);
        self::assertTrue($defaults->otpEnabled());
        self::assertTrue($defaults->finalEmailEnabled());

        $disabled = new FeatureFlags([
            'E7_PROPOSTAS_OTP_ENABLED' => '0',
            'E7_PROPOSTAS_FINAL_EMAIL_ENABLED' => 'false',
        ]);
        self::assertFalse($disabled->otpEnabled());
        self::assertFalse($disabled->finalEmailEnabled());
    }

    public function test_business_profile_is_normalized_for_ireland_and_eur(): void
    {
        self::assertTrue(class_exists(BusinessProfile::class), 'BusinessProfile must exist.');

        $profile = BusinessProfile::normalize($this->validBusinessProfile());

        self::assertSame('Aoife Murphy', $profile['responsible']['name']);
        self::assertSame('aoife@example.ie', $profile['responsible']['email']);
        self::assertSame('+353871234567', $profile['responsible']['phone']);
        self::assertSame('company', $profile['type']);
        self::assertSame('IE', $profile['registered_address']['country_code']);
        self::assertSame($profile['registered_address'], $profile['billing_address']);
        self::assertSame('rossmotorcycles.ie', $profile['domain']);
        self::assertSame('+353871112222', $profile['whatsapp']);
        self::assertTrue($profile['confirmations']['b2b']);
        self::assertTrue($profile['confirmations']['ireland']);
        self::assertTrue($profile['confirmations']['accuracy']);
    }

    #[DataProvider('invalidBusinessProfiles')]
    public function test_business_profile_rejects_missing_or_inconsistent_fiscal_data(array $changes): void
    {
        self::assertTrue(class_exists(BusinessProfile::class), 'BusinessProfile must exist.');

        $profile = array_replace_recursive($this->validBusinessProfile(), $changes);
        $this->expectException(\InvalidArgumentException::class);
        BusinessProfile::normalize($profile);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBusinessProfiles(): iterable
    {
        yield 'company registration is required' => [[
            'registration_number' => '',
        ]];
        yield 'vat number is required when registered' => [[
            'vat_registered' => true,
            'vat_number' => '',
        ]];
        yield 'responsible email is valid' => [[
            'responsible' => ['email' => 'not-an-email'],
        ]];
        yield 'international phone is required' => [[
            'responsible' => ['phone' => '087 123 4567'],
        ]];
        yield 'billing address is conditional' => [[
            'billing_same_as_registered' => false,
            'billing_address' => [],
        ]];
        yield 'payer details are conditional' => [[
            'payer_same_as_business' => false,
            'payer_legal_name' => '',
        ]];
        yield 'all confirmations are explicit' => [[
            'confirmations' => ['accuracy' => false],
        ]];
    }

    public function test_sole_trader_does_not_require_a_registration_number(): void
    {
        self::assertTrue(class_exists(BusinessProfile::class), 'BusinessProfile must exist.');

        $profile = $this->validBusinessProfile();
        $profile['type'] = 'sole_trader';
        $profile['registration_number'] = '';

        self::assertSame('', BusinessProfile::normalize($profile)['registration_number']);
    }

    public function test_invoice_items_require_descriptions_and_positive_integer_minor_amounts(): void
    {
        self::assertTrue(class_exists(InvoiceItems::class), 'InvoiceItems must exist.');

        $items = InvoiceItems::normalize([
            ['description' => '  Website   implementation ', 'amount_minor' => 125000],
            ['description' => 'Support', 'amount_minor' => 2500],
            ['description' => '', 'amount_minor' => ''],
        ]);

        self::assertSame([
            ['description' => 'Website implementation', 'amount_minor' => 125000],
            ['description' => 'Support', 'amount_minor' => 2500],
        ], $items);
        self::assertSame(127500, InvoiceItems::total($items));
    }

    #[DataProvider('invalidInvoiceItems')]
    public function test_invoice_items_reject_invalid_values(array $items): void
    {
        self::assertTrue(class_exists(InvoiceItems::class), 'InvoiceItems must exist.');

        $this->expectException(\InvalidArgumentException::class);
        InvoiceItems::normalize($items);
    }

    /** @return iterable<string, array{array<int, array<string, mixed>>}> */
    public static function invalidInvoiceItems(): iterable
    {
        yield 'zero amount' => [[['description' => 'Service', 'amount_minor' => 0]]];
        yield 'numeric string is not an integer' => [[['description' => 'Service', 'amount_minor' => '100']]];
        yield 'description is required with an amount' => [[['description' => '', 'amount_minor' => 100]]];
    }

    /** @return array<string, mixed> */
    private function validBusinessProfile(): array
    {
        return [
            'responsible' => [
                'name' => '  Aoife Murphy ',
                'role' => 'Director',
                'email' => 'AOIFE@EXAMPLE.IE',
                'phone' => '+353 87 123 4567',
            ],
            'type' => 'company',
            'legal_name' => 'Ross Motorcycles Limited',
            'trading_name' => 'Ross Motorcycles',
            'registration_number' => '123456',
            'vat_registered' => true,
            'vat_number' => 'IE1234567A',
            'registered_address' => [
                'line1' => '1 Main Street',
                'line2' => '',
                'city' => 'Cork',
                'county' => 'Cork',
                'eircode' => 'T12 AB34',
                'country_code' => 'IE',
            ],
            'billing_same_as_registered' => true,
            'billing_address' => [],
            'payer_same_as_business' => true,
            'payer_legal_name' => '',
            'finance_email' => 'finance@example.ie',
            'purchase_order' => 'PO-2026-01',
            'service_city' => 'Cork',
            'domain' => 'RossMotorcycles.ie',
            'whatsapp' => '+353 87 111 2222',
            'confirmations' => [
                'b2b' => true,
                'ireland' => true,
                'accuracy' => true,
            ],
        ];
    }
}
