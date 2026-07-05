<?php

declare(strict_types=1);

namespace AuthKit\Challenge;

/**
 * Persists and retrieves challenge records for multi-step login flows.
 *
 * Only the sha256 hash of the raw token is stored — the raw token travels
 * exclusively on the client side (e.g. $_SESSION['authkit_challenge']).
 *
 * @package AuthKit\Challenge
 */
interface ChallengeStorageInterface
{
    /**
     * Persist a new challenge record.
     *
     * @param ChallengeRecord $record   Challenge data to persist.
     * @param string          $rawToken Raw token sent to the client (sha256 hash stored in DB).
     * @return void
     */
    public function store(ChallengeRecord $record, string $rawToken): void;

    /**
     * Find a non-completed challenge by its raw token.
     *
     * @param  string $rawToken The raw token held by the client.
     * @return ChallengeRecord|null Null when not found or already completed.
     */
    public function findByToken(string $rawToken): ?ChallengeRecord;

    /**
     * Mark a challenge as successfully completed.
     *
     * @param  ChallengeRecord $record
     * @return void
     */
    public function complete(ChallengeRecord $record): void;

    /**
     * Increment the failed attempt counter for a challenge.
     *
     * @param  ChallengeRecord $record
     * @return void
     */
    public function incrementAttempts(ChallengeRecord $record): void;

    /**
     * Delete all expired challenge records.
     *
     * @return void
     */
    public function purgeExpired(): void;

    /**
     * Create the auth_challenges table if it does not exist.
     *
     * @return void
     */
    public function createSchema(): void;
}
