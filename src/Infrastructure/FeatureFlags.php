<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final readonly class FeatureFlags
{
    /** @param array<string, string|false>|null $environment */
    public function __construct(private ?array $environment = null)
    {
    }

    public function otpEnabled(): bool
    {
        return $this->enabled('E7_PROPOSTAS_OTP_ENABLED');
    }

    public function finalEmailEnabled(): bool
    {
        return $this->enabled('E7_PROPOSTAS_FINAL_EMAIL_ENABLED');
    }

    private function enabled(string $name): bool
    {
        $value = $this->environment === null ? getenv($name) : ($this->environment[$name] ?? false);
        if ($value === false || trim((string) $value) === '') {
            return true;
        }

        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'off', 'no', 'disabled'], true);
    }
}
