<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class AcceptancePolicy
{
    public static function phoneRequiredAtSubmission(bool $otpEnabled): bool
    {
        return ! $otpEnabled;
    }

    public static function phoneRequiredForVerifiedChannel(bool $otpEnabled, string $channel): bool
    {
        return ! $otpEnabled || $channel !== 'email';
    }

    public static function isIrishInvoiceFlow(string $locale, string $currency): bool
    {
        return $locale === 'en_IE' && $currency === 'EUR';
    }
}
