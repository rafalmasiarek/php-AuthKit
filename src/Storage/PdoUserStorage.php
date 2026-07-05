<?php

declare(strict_types=1);

namespace AuthKit\Storage;

use AuthKit\User;
use AuthKit\UserId\NullUserIdPolicy;
use AuthKit\UserId\UserIdPolicyInterface;
use DateTime;
use PDO;

/**
 * PDO-backed user storage supporting MySQL/MariaDB and SQLite.
 *
 * @package AuthKit\Storage
 */
final class PdoUserStorage implements UserStorageInterface
{
    /**
     * @param PDO                  $pdo          Database connection.
     * @param UserIdPolicyInterface $userIdPolicy ID generation policy (default: database auto-increment).
     */
    public function __construct(
        private readonly PDO                  $pdo,
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
    public function findByToken(string $token): ?User
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
            'now'   => (new DateTime())->format('Y-m-d H:i:s'),
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
    public function storeToken(User $user, string $token, ?\DateTime $expiresAt): void
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
     * @return void
     */
    public function purgeExpiredTokens(): void
    {
        $this->pdo->prepare(
            'DELETE FROM sessions WHERE expires_at IS NOT NULL AND expires_at < :now'
        )->execute(['now' => (new DateTime())->format('Y-m-d H:i:s')]);
    }

    /**
     * @inheritDoc
     */
    public function createSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    email         TEXT    NOT NULL UNIQUE,
                    password_hash TEXT    NOT NULL,
                    active        INTEGER NOT NULL DEFAULT 0
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    INTEGER NOT NULL,
                    token      TEXT    NOT NULL UNIQUE,
                    expires_at TEXT    DEFAULT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id            INT          NOT NULL AUTO_INCREMENT,
                    email         VARCHAR(255) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    active        TINYINT      NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id         INT          NOT NULL AUTO_INCREMENT,
                    user_id    INT          NOT NULL,
                    token      VARCHAR(255) NOT NULL,
                    expires_at DATETIME     DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_token (token),
                    INDEX      idx_user_id (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }
}
