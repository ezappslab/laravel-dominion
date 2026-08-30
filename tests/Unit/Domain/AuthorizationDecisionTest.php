<?php

use Infinity\Dominion\Domain\AuthorizationDecision;

it('converts decisions to Laravel Gate results', function (AuthorizationDecision $decision, ?bool $result): void {
    expect($decision->toGateResult())->toBe($result);
})->with([
    'allow' => [AuthorizationDecision::Allow, true],
    'deny' => [AuthorizationDecision::Deny, false],
    'abstain' => [AuthorizationDecision::Abstain, null],
]);
