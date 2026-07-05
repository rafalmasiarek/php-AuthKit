<?php

declare(strict_types=1);

namespace AuthKit\UserId;

/**
 * Delegates user ID assignment to the database (auto-increment).
 *
 * @package AuthKit\UserId
 */
final class NullUserIdPolicy implements UserIdPolicyInterface
{
    /**
     * @inheritDoc
     */
    public function generate(): int|string|null
    {
        return null;
    }
}
