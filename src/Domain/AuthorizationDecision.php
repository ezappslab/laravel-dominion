<?php

namespace Infinity\Dominion\Domain;

enum AuthorizationDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Abstain = 'abstain';

    /**
     * Convert this decision to the value expected by Laravel's Gate.
     */
    public function toGateResult(): ?bool
    {
        return match ($this) {
            self::Allow => true,
            self::Deny => false,
            self::Abstain => null,
        };
    }
}
