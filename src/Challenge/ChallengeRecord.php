<?php

declare(strict_types=1);

namespace AuthKit\Challenge;

use DateTime;

/**
 * Immutable value object representing a persisted challenge step.
 *
 * Created when a LoginExtensionInterface returns LoginDecision::challenge().
 * The raw token travels exclusively on the client side; only its sha256 hash
 * is stored in the database.
 *
 * @package AuthKit\Challenge
 */
final class ChallengeRecord
{
    /**
     * @param string             $id          UUID v4 record identifier.
     * @param int|string         $userId      ID of the user being challenged.
     * @param string             $type        Challenge type handled by a ChallengeExtensionInterface.
     * @param array<string,mixed>$payload     Data stored by the extension (e.g. hashed OTP, device info).
     * @param int                $maxAttempts Maximum allowed verification attempts before exhaustion.
     * @param DateTime           $expiresAt   Point in time when this challenge becomes invalid.
     * @param string             $ip          Client IP at time of challenge creation.
     * @param string             $userAgent   Client User-Agent at time of challenge creation.
     * @param int                $attempts    Current failed attempt count (mutable after hydration).
     * @param DateTime|null      $completedAt When the challenge was successfully completed (mutable).
     */
    public function __construct(
        public readonly string     $id,
        public readonly int|string $userId,
        public readonly string     $type,
        public readonly array      $payload,
        public readonly int        $maxAttempts,
        public readonly DateTime   $expiresAt,
        public readonly string     $ip,
        public readonly string     $userAgent,
        public int                 $attempts    = 0,
        public ?DateTime           $completedAt = null,
    ) {
    }

    /**
     * @return bool True when the challenge has passed its expiry time.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTime();
    }

    /**
     * @return bool True when the maximum attempt count has been reached.
     */
    public function isExhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /**
     * @return bool True when the challenge has been successfully completed.
     */
    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }
}
