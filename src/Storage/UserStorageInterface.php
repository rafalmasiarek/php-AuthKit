<?php

namespace AuthKit\Storage;

use AuthKit\User;


interface UserStorageInterface
{
    /**
     * Find a user by their email address.
     *
     * @param string $email The email address to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Find a user by their session token.
     *
     * @param string $token The session token to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findByToken(string $token): ?User;

    /**
     * Find a user by their ID.
     *
     * @param int $id The user ID to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function createUser(string $email, string $passwordHash, array $fields = []): User;

    /**
     * Update an existing user.
     *
     * @param User $user The user object to update.
     * @param array $fields Associative array of fields to update.
     * @return User Returns the updated User object.
     */
    public function updateUser(User $user, array $fields): User;

    /**
     * Store a session token for a user.
     *
     * @param User $user The user object.
     * @param string $token The session token to store.
     * @param \DateTime|null $expiresAt Optional expiration date/time for the token.
     */
    public function storeToken(User $user, string $token, ?\DateTime $expiresAt): void;

    /**
     * Delete a session token.
     *
     * @param string $token The session token to delete.
     * @return int The number of tokens that were deleted (mostly 0/1).
     */
    public function deleteToken(string $token): int;

    /**
     * Delete all session tokens for given user id.
     * @return int number of removed tokens.
     */
    public function deleteTokensByUserId(int $userId): int;

    /**
     * Create the necessary database schema for user storage.
     *
     * This method should be called to initialize the storage system.
     */
    public function createSchema(): void;

    /**
     * Find a user by their ID.
     *
     * @param int $id The user ID to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findById($id): ?User;
}
