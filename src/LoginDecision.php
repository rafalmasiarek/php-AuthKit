<?php

declare(strict_types=1);

namespace AuthKit;

/**
 * Immutable decision returned by LoginExtensionInterface::decide().
 *
 * Three possible states:
 *  - allow     : this extension permits the login pipeline to continue.
 *  - deny      : this extension blocks login; session will NOT be created.
 *  - challenge : an additional verification step is required before session creation.
 *
 * @package AuthKit
 */
final class LoginDecision
{
    private const ALLOW     = 'allow';
    private const DENY      = 'deny';
    private const CHALLENGE = 'challenge';

    private function __construct(
        private readonly string  $status,
        private readonly ?string $reason           = null,
        private readonly ?string $challengeType    = null,
        private readonly array   $challengePayload = [],
    ) {
    }

    /**
     * Allow the login pipeline to proceed to the next extension or session creation.
     *
     * @return self
     */
    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    /**
     * Block login with a human-readable reason.
     *
     * @param string $reason Error message returned to the caller (e.g. 'Account suspended.').
     * @return self
     */
    public static function deny(string $reason): self
    {
        return new self(self::DENY, reason: $reason);
    }

    /**
     * Require an additional verification step before session creation.
     *
     * @param string               $type    Challenge type handled by a ChallengeExtensionInterface.
     *                                      Examples: 'mfa_totp', 'email_activation', 'new_device'.
     * @param array<string, mixed> $payload Data stored with the challenge record (e.g. hashed OTP code).
     * @return self
     */
    public static function challenge(string $type, array $payload = []): self
    {
        return new self(self::CHALLENGE, challengeType: $type, challengePayload: $payload);
    }

    /**
     * @return bool True when this decision allows the pipeline to continue.
     */
    public function isAllow(): bool
    {
        return $this->status === self::ALLOW;
    }

    /**
     * @return bool True when this decision blocks login.
     */
    public function isDeny(): bool
    {
        return $this->status === self::DENY;
    }

    /**
     * @return bool True when this decision requires a challenge step.
     */
    public function isChallenge(): bool
    {
        return $this->status === self::CHALLENGE;
    }

    /**
     * @return string|null Denial reason (set only when isDeny() is true).
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return string|null Challenge type identifier (set only when isChallenge() is true).
     */
    public function challengeType(): ?string
    {
        return $this->challengeType;
    }

    /**
     * @return array<string, mixed> Challenge payload stored in the challenge record.
     */
    public function challengePayload(): array
    {
        return $this->challengePayload;
    }

    /**
     * @return string Raw status string ('allow', 'deny', 'challenge').
     */
    public function status(): string
    {
        return $this->status;
    }
}
