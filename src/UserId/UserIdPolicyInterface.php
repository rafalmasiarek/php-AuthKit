<?php

declare(strict_types=1);

namespace AuthKit\UserId;

/**
 * Determines how a new user ID is assigned before insertion.
 *
 * Return null to let the database generate the ID (auto-increment).
 * Return a value to supply the ID from the application side (e.g. UUID).
 *
 * @package AuthKit\UserId
 */
interface UserIdPolicyInterface
{
    /**
     * Generate an ID for a new user, or return null to delegate to the database.
     *
     * @return int|string|null Application-generated ID, or null for database auto-increment.
     */
    public function generate(): int|string|null;
}
