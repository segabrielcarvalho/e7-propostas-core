<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class FinalEmailState
{
    public const ABSENT = 'absent';
    public const CLAIMED = 'claimed';
    public const SENT = 'sent';

    private function __construct(private string $state)
    {
    }

    /** @param list<array<string, mixed>> $events */
    public static function fromEvents(array $events): self
    {
        $state = self::ABSENT;
        foreach ($events as $event) {
            if (($event['event_type'] ?? null) === 'final_email.sent') {
                return new self(self::SENT);
            }
            if (($event['event_type'] ?? null) === 'final_email.claimed') {
                $state = self::CLAIMED;
            }
        }
        return new self($state);
    }

    public function claim(): bool
    {
        if ($this->state !== self::ABSENT) {
            return false;
        }
        $this->state = self::CLAIMED;
        return true;
    }

    public function value(): string
    {
        return $this->state;
    }
}
