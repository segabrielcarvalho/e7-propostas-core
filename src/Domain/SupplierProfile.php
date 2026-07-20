<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class SupplierProfile
{
    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'legal_name' => 'E7 Company Tecnologia Ltda.',
            'tax_id' => '63.058.279/0001-84',
            'address' => 'Avenida Alvorada, 790, Apto 1508A',
            'district' => 'Chácaras Americanas',
            'city_state' => 'Anápolis/GO',
            'postal_code' => '75103-237',
            'country' => 'Brazil',
        ];
    }

    /** @param mixed $input @return array<string, string> */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $normalized = [];
        foreach (self::defaults() as $field => $default) {
            $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($input[$field] ?? $default))));
            if ($value === '' || strlen($value) > 190) {
                throw new \InvalidArgumentException('Supplier profile field is invalid: ' . $field);
            }
            $normalized[$field] = $value;
        }
        return $normalized;
    }
}
