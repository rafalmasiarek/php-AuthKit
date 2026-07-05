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
     * The implementation must record $now as the completion timestamp.
     * Pass Auth::now() or an equivalent value from your clock to keep
     * timestamps consistent with the rest of the auth layer.
     *
     * @param  ChallengeRecord    $record Challenge to mark as completed.
     * @param  \DateTimeImmutable $now    Current time used as the completed_at timestamp.
     * @return void
     */
    public function complete(ChallengeRecord $record, \DateTimeImmutable $now): void;

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
     * The caller is responsible for supplying the reference time.
     * Typically called from a maintenance job or cron — not part of the login flow.
     *
     * @param  \DateTimeImmutable $now Records with expires_at before this value will be deleted.
     * @return void
     */
    public function purgeExpired(\DateTimeImmutable $now): void;

    /**
     * Create the auth_challenges table if it does not exist.
     *
     * @return void
     */
    public function createSchema(): void;
}
