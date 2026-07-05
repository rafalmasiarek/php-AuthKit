<?php

declare(strict_types=1);

namespace AuthKit\Storage;

use AuthKit\Extension\SchemaProviderInterface;

/**
 * Marks a storage backend that is capable of creating its own database schema.
 *
 * Implement this interface alongside UserStorageInterface when the storage
 * layer can bootstrap its tables on demand (e.g. PdoUserStorage). Storage
 * implementations that keep data entirely in memory or delegate persistence to
 * an external service do not need to implement this.
 *
 * The Auth facade calls createSchema() when Auth::createSchema() is invoked,
 * passing all registered extensions that implement SchemaProviderInterface so
 * that each extension's required tables and columns are created in the same
 * transaction context.
 *
 * @package AuthKit\Storage
 */
interface SchemaMigrationInterface
{
    /**
     * Create the core schema plus any additional schema declared by the given providers.
     *
     * The method must be idempotent — safe to call multiple times against an
     * already-initialised database (e.g. use CREATE TABLE IF NOT EXISTS).
     *
     * @param  SchemaProviderInterface ...$providers Extension schema providers to run after core tables.
     * @return void
     */
    public function createSchema(SchemaProviderInterface ...$providers): void;
}
