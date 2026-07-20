<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceMoney
{
    private readonly string $currency;

    public function __construct(private readonly int $minor, string $currency)
    {
        $currency = strtoupper(trim($currency));
        if ($minor < 1) {
            throw new \InvalidArgumentException('Invoice money must be a positive integer in minor units.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invoice currency must be an ISO 4217 code.');
        }
        $this->currency = $currency;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Invoice money currencies must match.');
        }
        if ($other->minor > PHP_INT_MAX - $this->minor) {
            throw new \OverflowException('Invoice money exceeds the supported integer range.');
        }
        return new self($this->minor + $other->minor, $this->currency);
    }
}
