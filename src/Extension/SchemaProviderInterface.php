<?php

declare(strict_types=1);

namespace AuthKit\Extension;

/**
 * Declares additional database schema required by an extension or policy.
 *
 * Implement this interface on any class that needs custom tables or columns
 * alongside the core users/sessions tables. PdoUserStorage::createSchema()
 * accepts variadic SchemaProviderInterface instances and executes their SQL
 * after the core tables are created.
 *
 * Every statement in the returned array MUST be idempotent:
 * use CREATE TABLE IF NOT EXISTS, ALTER TABLE … ADD COLUMN IF NOT EXISTS, etc.
 *
 * @package AuthKit\Extension
 */
interface SchemaProviderInterface
{
    /**
     * Return SQL statements that set up the additional schema this provider needs.
     *
     * @param  string        $driver PDO driver name: 'mysql', 'sqlite', 'pgsql', etc.
     * @return list<string>  Idempotent SQL statements executed in order.
     */
    public function additionalSchema(string $driver): array;
}
