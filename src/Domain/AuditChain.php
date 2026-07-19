<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class AuditChain
{
    /** @param array<string, mixed> $event */
    public function next(?string $previousHash, array $event): string
    {
        $normalized = $this->normalize($event);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', ($previousHash ?? str_repeat('0', 64)) . $json);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value !== [] && ! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
