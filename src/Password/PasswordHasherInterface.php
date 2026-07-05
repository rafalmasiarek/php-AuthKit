<?php

declare(strict_types=1);

namespace AuthKit\Password;

/**
 * Hashes and verifies passwords for storage and authentication.
 *
 * @package AuthKit\Password
 */
interface PasswordHasherInterface
{
    /**
     * Hash a plain-text password for storage.
     *
     * @param  string $plain Plain-text password.
     * @return string Hashed password.
     */
    public function hash(string $plain): string;

    /**
     * Verify a plain-text password against a stored hash.
     *
     * @param  string $plain Plain-text password.
     * @param  string $hash  Stored hash to verify against.
     * @return bool True when the password matches the hash.
     */
    public function verify(string $plain, string $hash): bool;

    /**
     * Check whether an existing hash should be upgraded.
     *
     * @param  string $hash Stored hash to inspect.
     * @return bool True when the hash should be re-hashed on next login.
     */
    public function needsRehash(string $hash): bool;
}
