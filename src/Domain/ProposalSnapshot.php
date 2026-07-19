<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final readonly class ProposalSnapshot
{
    public function __construct(public string $canonicalJson, public string $hash)
    {
    }
}
