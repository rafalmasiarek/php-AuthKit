<?php

declare(strict_types=1);

namespace AuthKit\Storage;

use AuthKit\User;

/**
 * Persists and retrieves user records and session tokens.
 *
 * @package AuthKit\Storage
 */
interface UserStorageInterface
{
    /**
     * Find a user by their email address.
     *
     * @param  string $email The email address to search for.
     * @return User|null
     */
    public function findByEmail(string $email): ?User;

    /**
     * Find a user by their session token.
     *
     * $now is used to filter out expired sessions at the query level.
     * Pass the current time from your clock to ensure timezone-consistent
     * expiry comparisons against the stored expires_at column.
     *
     * @param  string            $token The session token to search for.
     * @param  \DateTimeImmutable $now   Current time used to filter expired sessions.
     * @return User|null
     */
    public function findByToken(string $token, \DateTimeImmutable $now): ?User;

    /**
     * Find a user by their ID.
     *
     * @param  int|string $id The user ID to search for.
     * @return User|null
     */
    public function findById(int|string $id): ?User;

    /**
     * Create and persist a new user.
     *
     * @param  string               $email        User's email address.
     * @param  string               $passwordHash Hashed password.
     * @param  array<string, mixed> $fields       Additional fields to store.
     * @return User
     */
    public function createUser(string $email, string $passwordHash, array $fields = []): User;

    /**
     * Update fields on an existing user.
     *
     * @param  User                 $user   The user to update.
     * @param  array<string, mixed> $fields Associative array of fields to update.
     * @return User Updated user.
     */
    public function updateUser(User $user, array $fields): User;

    /**
     * Store a session token for a user.
     *
     * @param  User                   $user      The user to associate the token with.
     * @param  string                 $token     The session token to store.
     * @param  \DateTimeInterface|null $expiresAt Optional expiration date/time.
     * @return void
     */
    public function storeToken(User $user, string $token, ?\DateTimeInterface $expiresAt): void;

    /**
     * Delete a single session token.
     *
     * @param  string $token The session token to delete.
     * @return int Number of tokens deleted (0 or 1).
     */
    public function deleteToken(string $token): int;

    /**
     * Delete all session tokens for a given user.
     *
     * @param  int|string $userId The user ID whose tokens should be removed.
     * @return int Number of tokens deleted.
     */
    public function deleteTokensByUserId(int|string $userId): int;

    /**
     * Create the necessary database schema for user storage.
     *
     * @return void
     */
    public function createSchema(): void;
}
