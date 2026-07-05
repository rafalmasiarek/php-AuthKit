<?php

declare(strict_types=1);

namespace AuthKit\Storage;

use AuthKit\Extension\SchemaProviderInterface;
use AuthKit\User;
use AuthKit\UserId\NullUserIdPolicy;
use AuthKit\UserId\UserIdPolicyInterface;
use PDO;

/**
 * PDO-backed user storage supporting MySQL/MariaDB and SQLite.
 *
 * Implements SchemaMigrationInterface so that Auth::createSchema() can
 * bootstrap the required tables and delegate to registered extension providers.
 *
 * @package AuthKit\Storage
 */
final class PdoUserStorage implements UserStorageInterface, SchemaMigrationInterface
{
    /**
     * @param PDO                  $pdo          Database connection.
     * @param UserIdPolicyInterface $userIdPolicy ID generation policy (default: database auto-increment).
     */
    public function __construct(
        private readonly PDO                   $pdo,
        private readonly UserIdPolicyInterface $userIdPolicy = new NullUserIdPolicy(),
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function findByToken(string $token, \DateTimeImmutable $now): ?User
    {
        $stmt = $this->pdo->prepare('
            SELECT u.* FROM users u
            JOIN sessions s ON s.user_id = u.id
            WHERE s.token = :token
              AND (s.expires_at IS NULL OR s.expires_at > :now)
            LIMIT 1
        ');
        $stmt->execute([
            'token' => $token,
            'now'   => $now->format('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function findById(int|string $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function createUser(string $email, string $passwordHash, array $fields = []): User
    {
        $generatedId = $this->userIdPolicy->generate();

        $allFields = array_merge(
            $generatedId !== null ? ['id' => $generatedId] : [],
            ['email' => $email, 'password_hash' => $passwordHash],
            $fields,
        );

        $columns      = implode(', ', array_map(fn($k) => "`$k`", array_keys($allFields)));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($allFields)));

        $stmt = $this->pdo->prepare("INSERT INTO users ($columns) VALUES ($placeholders)");
        $stmt->execute($allFields);

        $allFields['id'] = $generatedId ?? $this->pdo->lastInsertId();

        return new User($allFields);
    }

    /**
     * @inheritDoc
     */
    public function updateUser(User $user, array $fields): User
    {
        if (empty($fields)) {
            return $user;
        }

        $updates = [];
        $params  = [];

        foreach ($fields as $key => $value) {
            $updates[]       = "`$key` = :$key";
            $params[":$key"] = $value;
        }

        $params[':id'] = $user->getId();

        $this->pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id')
            ->execute($params);

        $id = $user->getId();

        return $id !== null ? ($this->findById($id) ?? $user) : $user;
    }

    /**
     * @inheritDoc
     */
    public function storeToken(User $user, string $token, ?\DateTimeInterface $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (user_id, token, expires_at) VALUES (:uid, :token, :exp)'
        );
        $stmt->execute([
            'uid'   => $user->getId(),
            'token' => $token,
            'exp'   => $expiresAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function deleteToken(string $token): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE token = :token');
        $stmt->execute(['token' => $token]);

        return $stmt->rowCount();
    }

    /**
     * @inheritDoc
     */
    public function deleteTokensByUserId(int|string $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);

        return $stmt->rowCount();
    }

    /**
     * Delete all expired session tokens.
     *
     * Typically called from a maintenance job or cron — not part of the login flow.
     * The caller is responsible for supplying the reference time to ensure
     * timezone-consistent comparisons against the stored expires_at column.
     *
     * @param  \DateTimeImmutable $now Sessions with expires_at before this value will be deleted.
     * @return void
     */
    public function purgeExpiredTokens(\DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'DELETE FROM sessions WHERE expires_at IS NOT NULL AND expires_at < :now'
        )->execute(['now' => $now->format('Y-m-d H:i:s')]);
    }

    /**
     * Create the core users/sessions tables and run any additional schema declared
     * by the given providers.
     *
     * Column types for the primary key and foreign key columns are determined by
     * the injected UserIdPolicyInterface so that the schema matches the chosen ID
     * strategy (e.g. INT AUTO_INCREMENT for NullUserIdPolicy, CHAR(36) for UUID).
     *
     * The method is idempotent — safe to call multiple times.
     *
     * @param  SchemaProviderInterface ...$providers Extension providers whose
     *         additionalSchema() statements are executed after the core tables.
     * @return void
     */
    public function createSchema(SchemaProviderInterface ...$providers): void
    {
        $driver  = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idDef   = $this->userIdPolicy->idColumnDefinition($driver);
        $fkType  = $this->userIdPolicy->userIdForeignType($driver);
        $hasPkInline = str_contains(strtoupper($idDef), 'PRIMARY KEY');

        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $pkClause = $hasPkInline ? '' : ', PRIMARY KEY (id)';
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id            {$idDef},
                    email         TEXT NOT NULL,
                    password_hash TEXT NULL{$pkClause},
                    UNIQUE (email)
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    {$fkType},
                    token      TEXT NOT NULL UNIQUE,
                    expires_at TEXT DEFAULT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");
        } else {
            $pkClause = $hasPkInline ? '' : 'PRIMARY KEY (id),';
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id            {$idDef},
                    email         VARCHAR(255) NOT NULL,
                    password_hash VARCHAR(255) NULL,
                    {$pkClause}
                    UNIQUE KEY uq_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id         INT          NOT NULL AUTO_INCREMENT,
                    user_id    {$fkType},
                    token      VARCHAR(255) NOT NULL,
                    expires_at DATETIME     DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_token (token),
                    INDEX      idx_user_id (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        foreach ($providers as $provider) {
            foreach ($provider->additionalSchema($driver) as $sql) {
                $this->pdo->exec($sql);
            }
        }
    }
}
