<?php

declare(strict_types=1);

namespace AuthKit\Challenge;

use DateTime;
use PDO;

/**
 * PDO-backed challenge storage supporting MySQL/MariaDB and SQLite.
 *
 * Token security: only sha256($rawToken) is stored. The raw token is returned
 * to the caller and kept client-side. A DB leak cannot be used to replay challenges.
 *
 * @package AuthKit\Challenge
 */
final class PdoChallengeStorage implements ChallengeStorageInterface
{
    /**
     * @param PDO $pdo Database connection.
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @inheritDoc
     */
    public function store(ChallengeRecord $record, string $rawToken): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_challenges
             (id, user_id, token_hash, type, payload, attempts, max_attempts, expires_at, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $record->id,
            $record->userId,
            hash('sha256', $rawToken),
            $record->type,
            json_encode($record->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $record->attempts,
            $record->maxAttempts,
            $record->expiresAt->format('Y-m-d H:i:s'),
            $record->ip,
            $record->userAgent,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function findByToken(string $rawToken): ?ChallengeRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM auth_challenges
             WHERE token_hash = ? AND completed_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function complete(ChallengeRecord $record, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE auth_challenges SET completed_at = ? WHERE id = ?'
        )->execute([$now->format('Y-m-d H:i:s'), $record->id]);
    }

    /**
     * @inheritDoc
     */
    public function incrementAttempts(ChallengeRecord $record): void
    {
        $this->pdo->prepare(
            'UPDATE auth_challenges SET attempts = attempts + 1 WHERE id = ?'
        )->execute([$record->id]);
    }

    /**
     * @inheritDoc
     */
    public function purgeExpired(\DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'DELETE FROM auth_challenges WHERE expires_at < ?'
        )->execute([$now->format('Y-m-d H:i:s')]);
    }

    /**
     * @inheritDoc
     */
    public function createSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auth_challenges (
                    id           TEXT    NOT NULL PRIMARY KEY,
                    user_id      TEXT    NOT NULL,
                    token_hash   TEXT    NOT NULL UNIQUE,
                    type         TEXT    NOT NULL,
                    payload      TEXT    NOT NULL DEFAULT '{}',
                    attempts     INTEGER NOT NULL DEFAULT 0,
                    max_attempts INTEGER NOT NULL DEFAULT 5,
                    ip           TEXT    NOT NULL DEFAULT '',
                    user_agent   TEXT    NOT NULL DEFAULT '',
                    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                    expires_at   TEXT    NOT NULL,
                    completed_at TEXT    DEFAULT NULL
                )
            ");
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_challenges_user_id ON auth_challenges (user_id)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_challenges_expires ON auth_challenges (expires_at)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_challenges_user_type_completed ON auth_challenges (user_id, type, completed_at)');
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS auth_challenges (
                    id           VARCHAR(36)  NOT NULL,
                    user_id      VARCHAR(255) NOT NULL,
                    token_hash   CHAR(64)     NOT NULL,
                    type         VARCHAR(64)  NOT NULL,
                    payload      JSON         NOT NULL,
                    attempts     TINYINT      NOT NULL DEFAULT 0,
                    max_attempts TINYINT      NOT NULL DEFAULT 5,
                    ip           VARCHAR(45)  NOT NULL DEFAULT '',
                    user_agent   VARCHAR(512) NOT NULL DEFAULT '',
                    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at   DATETIME     NOT NULL,
                    completed_at DATETIME     DEFAULT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY   uq_token_hash (token_hash),
                    INDEX        idx_user_id   (user_id),
                    INDEX        idx_expires   (expires_at),
                    INDEX        idx_user_type_completed (user_id, type, completed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    /**
     * Hydrate a database row into a ChallengeRecord.
     *
     * @param  array<string, mixed> $row
     * @return ChallengeRecord
     */
    private function hydrate(array $row): ChallengeRecord
    {
        return new ChallengeRecord(
            id:          (string) $row['id'],
            userId:      $row['user_id'],
            type:        (string) $row['type'],
            payload:     json_decode((string) $row['payload'], true) ?? [],
            maxAttempts: (int)    $row['max_attempts'],
            expiresAt:   new DateTime((string) $row['expires_at']),
            ip:          (string) $row['ip'],
            userAgent:   (string) $row['user_agent'],
            attempts:    (int)    $row['attempts'],
            completedAt: !empty($row['completed_at']) ? new DateTime((string) $row['completed_at']) : null,
        );
    }
}
