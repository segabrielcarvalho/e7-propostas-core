<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\IrishVatNumber;
use E7Propostas\Infrastructure\ViesClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViesTest extends TestCase
{
    #[DataProvider('validVatNumbers')]
    public function test_irish_vat_is_normalized_for_the_official_service(string $input, string $expected): void
    {
        self::assertSame($expected, IrishVatNumber::normalize($input));
        self::assertSame(substr($expected, 2), IrishVatNumber::domesticPart($expected));
    }

    /** @return iterable<string, array{string, string}> */
    public static function validVatNumbers(): iterable
    {
        yield 'modern' => [' ie 6388047v ', 'IE6388047V'];
        yield 'two check letters' => ['IE1234567AB', 'IE1234567AB'];
        yield 'legacy' => ['8A12345A', 'IE8A12345A'];
    }

    public function test_blank_vat_is_absent_and_malformed_vat_is_rejected(): void
    {
        self::assertNull(IrishVatNumber::normalize('  '));

        $this->expectException(\InvalidArgumentException::class);
        IrishVatNumber::normalize('GB123456789');
    }

    public function test_vies_response_keeps_only_minimal_non_pii_evidence(): void
    {
        $result = ViesClient::parseResponse(<<<XML
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><checkVatResponse xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><countryCode>IE</countryCode><vatNumber>6388047V</vatNumber><requestDate>2026-07-20+02:00</requestDate><valid>true</valid><name>PRIVATE CUSTOMER LIMITED</name><address>PRIVATE ADDRESS</address></checkVatResponse></soap:Body></soap:Envelope>
XML);

        self::assertSame('valid', $result['status']);
        self::assertSame('IE', $result['evidence']['country_code']);
        self::assertSame('2026-07-20', $result['evidence']['request_date']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evidence']['response_hash']);
        self::assertArrayNotHasKey('name', $result['evidence']);
        self::assertArrayNotHasKey('address', $result['evidence']);
    }

    public function test_vies_invalid_response_is_a_business_result_not_an_exception(): void
    {
        $result = ViesClient::parseResponse('<checkVatResponse><countryCode>IE</countryCode><vatNumber>1234567A</vatNumber><requestDate>2026-07-20</requestDate><valid>false</valid></checkVatResponse>');

        self::assertSame('invalid', $result['status']);
    }
}
