<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class BusinessProfile
{
    /** @param mixed $input @return array<string, mixed> */
    public static function normalize(mixed $input): array
    {
        if (! is_array($input)) {
            throw new \InvalidArgumentException('business_profile must be an object.');
        }

        $responsibleInput = self::object($input['responsible'] ?? null, 'responsible');
        $responsible = [
            'name' => self::requiredText($responsibleInput['name'] ?? null, 'responsible.name'),
            'role' => self::requiredText($responsibleInput['role'] ?? null, 'responsible.role'),
            'email' => self::email($responsibleInput['email'] ?? null, true, 'responsible.email'),
            'phone' => self::phone($responsibleInput['phone'] ?? null, true, 'responsible.phone'),
        ];

        $type = self::text($input['type'] ?? '');
        if (! in_array($type, ['company', 'sole_trader'], true)) {
            throw new \InvalidArgumentException('type must be company or sole_trader.');
        }
        $registration = self::text($input['registration_number'] ?? '');
        if ($type === 'company' && $registration === '') {
            throw new \InvalidArgumentException('registration_number is required for a company.');
        }

        $vatRegistered = self::boolean($input['vat_registered'] ?? null, 'vat_registered');
        $vat = strtoupper(str_replace([' ', '-', '.'], '', self::text($input['vat_number'] ?? '')));
        if ($vatRegistered && ! preg_match('/^IE[A-Z0-9]{7,10}$/', $vat)) {
            throw new \InvalidArgumentException('vat_number is required and must be a valid Irish VAT format when vat_registered is true.');
        }
        if (! $vatRegistered) {
            $vat = '';
        }

        $registered = self::address($input['registered_address'] ?? null, 'registered_address');
        $billingSame = self::boolean($input['billing_same_as_registered'] ?? null, 'billing_same_as_registered');
        $billing = $billingSame
            ? $registered
            : self::address($input['billing_address'] ?? null, 'billing_address');
        $payerSame = self::boolean($input['payer_same_as_business'] ?? null, 'payer_same_as_business');
        $payerLegalName = self::text($input['payer_legal_name'] ?? '');
        if (! $payerSame && $payerLegalName === '') {
            throw new \InvalidArgumentException('payer_legal_name is required when the payer differs from the business.');
        }
        if ($payerSame) {
            $payerLegalName = '';
        }

        $confirmationsInput = self::object($input['confirmations'] ?? null, 'confirmations');
        $confirmations = [];
        foreach (['b2b', 'ireland', 'accuracy'] as $confirmation) {
            $confirmations[$confirmation] = self::boolean($confirmationsInput[$confirmation] ?? null, 'confirmations.' . $confirmation);
            if (! $confirmations[$confirmation]) {
                throw new \InvalidArgumentException('All business confirmations must be accepted.');
            }
        }

        return [
            'responsible' => $responsible,
            'type' => $type,
            'legal_name' => self::requiredText($input['legal_name'] ?? null, 'legal_name'),
            'trading_name' => self::requiredText($input['trading_name'] ?? null, 'trading_name'),
            'registration_number' => $registration,
            'vat_registered' => $vatRegistered,
            'vat_number' => $vat,
            'registered_address' => $registered,
            'billing_same_as_registered' => $billingSame,
            'billing_address' => $billing,
            'payer_same_as_business' => $payerSame,
            'payer_legal_name' => $payerLegalName,
            'finance_email' => self::email($input['finance_email'] ?? '', false, 'finance_email'),
            'purchase_order' => self::optionalText($input['purchase_order'] ?? '', 190, 'purchase_order'),
            'service_city' => self::requiredText($input['service_city'] ?? null, 'service_city'),
            'domain' => self::domain($input['domain'] ?? ''),
            'whatsapp' => self::phone($input['whatsapp'] ?? '', false, 'whatsapp'),
            'confirmations' => $confirmations,
        ];
    }

    /** @return array{line1: string, line2: string, city: string, county: string, eircode: string, country_code: string} */
    private static function address(mixed $value, string $field): array
    {
        $address = self::object($value, $field);
        $country = strtoupper(self::text($address['country_code'] ?? ''));
        if ($country !== 'IE') {
            throw new \InvalidArgumentException($field . '.country_code must be IE.');
        }
        return [
            'line1' => self::requiredText($address['line1'] ?? null, $field . '.line1'),
            'line2' => self::optionalText($address['line2'] ?? '', 190, $field . '.line2'),
            'city' => self::requiredText($address['city'] ?? null, $field . '.city'),
            'county' => self::requiredText($address['county'] ?? null, $field . '.county'),
            'eircode' => self::requiredText($address['eircode'] ?? null, $field . '.eircode'),
            'country_code' => 'IE',
        ];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException($field . ' must be an object.');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return (bool) (int) $value;
        }
        throw new \InvalidArgumentException($field . ' must be boolean.');
    }

    private static function requiredText(mixed $value, string $field): string
    {
        $text = self::optionalText($value, 190, $field);
        if ($text === '') {
            throw new \InvalidArgumentException($field . ' is required.');
        }
        return $text;
    }

    private static function optionalText(mixed $value, int $maxLength, string $field): string
    {
        $text = self::text($value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > $maxLength) {
            throw new \InvalidArgumentException($field . ' is too long.');
        }
        return $text;
    }

    private static function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    }

    private static function email(mixed $value, bool $required, string $field): string
    {
        $email = strtolower(self::text($value));
        if ($email === '' && ! $required) {
            return '';
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new \InvalidArgumentException($field . ' must be a valid email address.');
        }
        return $email;
    }

    private static function phone(mixed $value, bool $required, string $field): string
    {
        $phone = preg_replace('/[\s().-]+/', '', self::text($value));
        if ($phone === '' && ! $required) {
            return '';
        }
        if (! is_string($phone) || ! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            throw new \InvalidArgumentException($field . ' must be an international E.164 phone number.');
        }
        return $phone;
    }

    private static function domain(mixed $value): string
    {
        $domain = strtolower(self::text($value));
        if ($domain === '') {
            return '';
        }
        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }
        $domain = rtrim($domain, '.');
        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('domain must be a valid hostname.');
        }
        return $domain;
    }
}
