<?php

namespace Infinity\Dominion\Domain;

/**
 * Represents Dominion's final, deny-by-default authorization result.
 */
enum AuthorizationDecision: string
{
    /** The evaluated permission is granted. */
    case Allow = 'allow';

    /** The evaluated permission is refused. */
    case Deny = 'deny';
}
