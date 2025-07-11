<?php

namespace AuthKit\Storage;

use PDO;
use DateTime;
use AuthKit\User;
use AuthKit\Storage\UserStorageInterface;

class PdoUserStorage implements UserStorageInterface
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Connected PDO instance
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find user by email.
     * @param string $email
     * @return User|null
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * Find user by valid session token.
     * @param string $token
     * @return User|null
     */
    public function findByToken(string $token): ?User
    {
        $stmt = $this->pdo->prepare("
            SELECT u.* FROM users u
            JOIN sessions s ON s.user_id = u.id
            WHERE s.token = :token
              AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
            ORDER BY s.expires_at DESC
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * Find user by ID.
     * @param int $id
     * @return User|null
     */
    public function findById($id): ?User
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * Create and return a new user.
     * @param string $email
     * @param string $passwordHash
     * @param array $fields
     * @return User
     */
    public function createUser(string $email, string $passwordHash, array $fields = []): User
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email.");
        }

        $allFields = array_merge([
            'email' => $email,
            'password_hash' => $passwordHash,
        ], $fields);

        $columns = implode(', ', array_map(fn($key) => "`$key`", array_keys($allFields)));
        $placeholders = implode(', ', array_map(fn($key) => ":$key", array_keys($allFields)));

        $stmt = $this->pdo->prepare("
            INSERT INTO users ($columns)
            VALUES ($placeholders)
        ");
        $stmt->execute($allFields);

        $allFields['id'] = (int) $this->pdo->lastInsertId();
        return new User($allFields);
    }

    /**
     * Store session token for user.
     * @param User $user
     * @param string $token
     * @param DateTime|null $expiresAt
     */
    public function storeToken(User $user, string $token, ?DateTime $expiresAt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (user_id, token, expires_at)
            VALUES (:user_id, :token, :expires_at)
        ");
        $stmt->execute([
            'user_id' => $user->getId(),
            'token' => $token,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Delete session token.
     * @param string $token
     */
    public function deleteToken(string $token): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM sessions
            WHERE token = :token
        ");
        $stmt->execute(['token' => $token]);
    }

    /**
     * Update user data and return updated user.
     * @param User $user
     * @param array $fields
     * @return User
     */
    public function updateUser(User $user, array $fields): User
    {
        if (empty($fields)) {
            return $user;
        }

        $updates = [];
        $params = [];

        foreach ($fields as $key => $value) {
            $updates[] = "`$key` = :$key";
            $params[$key] = $value;
        }

        $params['id'] = $user->getId();

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findByEmail($fields['email'] ?? $user->getEmail());
    }

    /**
     * Delete expired tokens.
     */
    public function purgeExpiredTokens(): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM sessions
            WHERE expires_at IS NOT NULL
              AND expires_at < :now
        ");
        $stmt->execute([
            'now' => (new DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create users/sessions tables if not exist.
     */
    public function createSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $types = $driver === 'mysql'
            ? ['id' => 'INT AUTO_INCREMENT PRIMARY KEY', 'text' => 'VARCHAR(255)', 'datetime' => 'DATETIME']
            : ['id' => 'INTEGER PRIMARY KEY AUTOINCREMENT', 'text' => 'TEXT', 'datetime' => 'TEXT'];

        if ($driver === 'sqlite') {
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id {$types['id']},
                email {$types['text']} NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                active INTEGER DEFAULT 0
            );
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id {$types['id']},
                user_id INTEGER NOT NULL,
                token {$types['text']} NOT NULL UNIQUE,
                expires_at {$types['datetime']},
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);");
    }
}
