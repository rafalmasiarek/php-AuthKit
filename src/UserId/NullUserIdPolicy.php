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

    /**
     * @inheritDoc
     */
    public function idColumnDefinition(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default  => 'INT NOT NULL AUTO_INCREMENT',
        };
    }

    /**
     * @inheritDoc
     */
    public function userIdForeignType(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'INTEGER NOT NULL',
            default  => 'INT NOT NULL',
        };
    }
}
