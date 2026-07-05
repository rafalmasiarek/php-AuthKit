<?php

declare(strict_types=1);

namespace AuthKit\UserId;

/**
 * Determines how a new user ID is assigned before insertion and what column
 * type that choice requires in the database schema.
 *
 * Return null from generate() to let the database produce the ID (auto-increment).
 * Return a value to supply the ID from the application layer (e.g. UUID v4).
 *
 * The two schema methods allow PdoUserStorage::createSchema() to emit the
 * correct column definitions without hardcoding a specific strategy.
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

    /**
     * Full inline SQL column definition for the users.id primary key column.
     *
     * When the returned string contains the token "PRIMARY KEY", createSchema()
     * omits the separate PRIMARY KEY table constraint (required for SQLite
     * AUTOINCREMENT which must be declared inline).
     *
     * Examples:
     *  NullUserIdPolicy / sqlite  → "INTEGER PRIMARY KEY AUTOINCREMENT"
     *  NullUserIdPolicy / mysql   → "INT NOT NULL AUTO_INCREMENT"
     *  UuidUserIdPolicy / any     → "CHAR(36) NOT NULL"
     *
     * @param  string $driver PDO driver name ('mysql', 'sqlite', …).
     * @return string SQL fragment placed immediately after the column name.
     */
    public function idColumnDefinition(string $driver): string;

    /**
     * SQL type for foreign key columns that reference users.id.
     *
     * Used by createSchema() for the sessions.user_id column and should be
     * compatible with the type chosen by idColumnDefinition().
     *
     * Examples:
     *  NullUserIdPolicy / sqlite  → "INTEGER NOT NULL"
     *  NullUserIdPolicy / mysql   → "INT NOT NULL"
     *  UuidUserIdPolicy / any     → "CHAR(36) NOT NULL"
     *
     * @param  string $driver PDO driver name.
     * @return string SQL type fragment (no column name).
     */
    public function userIdForeignType(string $driver): string;
}
