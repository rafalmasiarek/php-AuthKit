<?php

declare(strict_types=1);

namespace AuthKit\Extension;

use AuthKit\Challenge\ChallengeRecord;
use AuthKit\LoginDecision;

/**
 * Extension that both initiates and handles a specific challenge type.
 *
 * When decide() returns LoginDecision::challenge($type), Auth stores a ChallengeRecord
 * and returns a challenge token to the caller. When the user submits their response,
 * Auth::completeChallenge() dispatches to the matching ChallengeExtensionInterface.
 *
 * Only Auth creates the session — never the extension.
 *
 * @package AuthKit\Extension
 */
interface ChallengeExtensionInterface extends LoginExtensionInterface
{
    /**
     * Returns true when this extension handles the given challenge type.
     *
     * @param  string $type Challenge type identifier (e.g. 'mfa_totp', 'email_activation').
     * @return bool
     */
    public function supportsChallenge(string $type): bool;

    /**
     * Verify the user's response to a pending challenge.
     *
     * Return LoginDecision::allow() to let Auth create the session.
     * Return LoginDecision::deny(reason) on invalid input — Auth increments the attempt counter.
     *
     * @param  ChallengeRecord      $challenge The persisted challenge record.
     * @param  array<string, mixed> $input     User-supplied verification data (e.g. ['code' => '123456']).
     * @return LoginDecision allow() or deny(reason).
     */
    public function completeChallenge(ChallengeRecord $challenge, array $input): LoginDecision;
}
