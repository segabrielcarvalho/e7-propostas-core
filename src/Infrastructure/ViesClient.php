<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use E7Propostas\Domain\IrishVatNumber;

final class ViesClient
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';

    /** @return array{status: string, checked_at: string, evidence: array<string, string>} */
    public function check(string $vatNumber): array
    {
        $normalized = IrishVatNumber::normalize($vatNumber);
        if ($normalized === null) {
            return ['status' => 'not_requested', 'checked_at' => gmdate('Y-m-d H:i:s'), 'evidence' => []];
        }
        $domestic = IrishVatNumber::domesticPart($normalized);
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:v="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
            . '<soap:Body><v:checkVat><v:countryCode>IE</v:countryCode><v:vatNumber>' . htmlspecialchars($domestic, ENT_XML1) . '</v:vatNumber></v:checkVat></soap:Body></soap:Envelope>';
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 4,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => 'checkVat'],
            'body' => $body,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [
                'status' => 'unavailable',
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'evidence' => ['request_hash' => hash('sha256', $normalized)],
            ];
        }
        try {
            $parsed = self::parseResponse(wp_remote_retrieve_body($response));
            $parsed['checked_at'] = gmdate('Y-m-d H:i:s');
            return $parsed;
        } catch (\Throwable) {
            return [
                'status' => 'unavailable',
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'evidence' => ['request_hash' => hash('sha256', $normalized)],
            ];
        }
    }

    /** @return array{status: string, evidence: array<string, string>} */
    public static function parseResponse(string $xml): array
    {
        $country = self::element($xml, 'countryCode');
        $valid = strtolower(self::element($xml, 'valid'));
        $requestDate = substr(self::element($xml, 'requestDate'), 0, 10);
        if ($country !== 'IE' || ! in_array($valid, ['true', 'false'], true)) {
            throw new \RuntimeException('VIES returned an invalid response.');
        }
        $canonical = preg_replace('/>\s+</', '><', trim($xml));
        return [
            'status' => $valid === 'true' ? 'valid' : 'invalid',
            'evidence' => [
                'country_code' => 'IE',
                'request_date' => $requestDate,
                'response_hash' => hash('sha256', is_string($canonical) ? $canonical : $xml),
            ],
        ];
    }

    private static function element(string $xml, string $name): string
    {
        if (! preg_match('/<(?:[A-Za-z0-9_-]+:)?' . preg_quote($name, '/') . '\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_-]+:)?' . preg_quote($name, '/') . '>/si', $xml, $match)) {
            throw new \RuntimeException('VIES response field is missing.');
        }
        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
