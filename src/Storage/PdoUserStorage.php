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
     * PdoUserStorage constructor.
     *
     * @param PDO $pdo PDO instance connected to the database.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find a user by their email address.
     *
     * @param string $email The email address to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        return new User($row);
    }

    /**
     * Find a user by their session token.
     *
     * @param string $token The session token to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findByToken(string $token): ?User
    {
        $stmt = $this->pdo->prepare("
            SELECT u.* FROM users u
            JOIN sessions s ON s.user_id = u.id
            WHERE s.token = :token AND s.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        return new User($row);
    }

    /**
     * Find a user by their ID.
     *
     * @param int $id The user ID to search for.
     * @return User|null Returns a User object if found, or null if not found.
     */
    public function findById($id): ?User
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        return new User($row);
    }

    /**
     * Create a new user in the database.
     *
     * @param string $email The email address of the user.
     * @param string $passwordHash The hashed password of the user.
     * @param array $fields Additional fields to store in the user record.
     * @return User Returns the created User object.
     */
    public function createUser(string $email, string $passwordHash, array $fields = []): User
    {
        $allFields = array_merge([
            'email' => $email,
            'password_hash' => $passwordHash,
        ], $fields);

        $columns = implode(', ', array_map(fn($key) => "`$key`", array_keys($allFields)));
        $placeholders = implode(', ', array_map(fn($key) => ":$key", array_keys($allFields)));

        $stmt = $this->pdo->prepare("INSERT INTO users ($columns) VALUES ($placeholders)");
        $stmt->execute($allFields);

        $id = (int) $this->pdo->lastInsertId();
        $allFields['id'] = $id;

        return new User($allFields);
    }

    /**
     * Store a session token for a user.
     *
     * @param User $user The user to associate the token with.
     * @param string $token The session token to store.
     * @param DateTime|null $expiresAt Optional expiration date for the token.
     */
    public function storeToken(User $user, string $token, ?\DateTime $expiresAt): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO sessions (user_id, token, expires_at) VALUES (:uid, :token, :exp)");
        $stmt->execute([
            'uid' => $user->getId(),
            'token' => $token,
            'exp' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
        ]);
    }

    /**
     * Delete a session token from the database.
     *
     * @param string $token The session token to delete.
     */
    public function deleteToken(string $token): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->execute(['token' => $token]);
    }

    /**
     * Delete all session tokens belonging to a specific user.
     *
     * This method invalidates all active sessions for the given user ID by
     * removing corresponding records from the `sessions` table.
     * Typically used for administrative or security actions (e.g. force logout).
     *
     * @param int $userId The ID of the user whose sessions should be deleted.
     *
     * @return int The number of sessions that were removed.
     */
    public function deleteTokensByUserId(int $userId): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Update a user's information in the database.
     *
     * @param User $user The user to update.
     * @param array $fields Associative array of fields to update.
     * @return User Returns the updated User object.
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
            $params[":$key"] = $value;
        }

        $params[":id"] = $user->getId();
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findByEmail($fields['email'] ?? $user->getEmail());
    }

    /**
     * Purge expired session tokens from the database.
     */
    public function purgeExpiredTokens(): void
    {
        $this->pdo->prepare("
        DELETE FROM sessions 
        WHERE expires_at IS NOT NULL AND expires_at < :now
    ")->execute([
            'now' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create the necessary database schema for user and session management.
     *
     * This method creates the `users` and `sessions` tables if they do not already exist.
     * It also sets up foreign key constraints and enables foreign key support for SQLite.
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
		        password_hash {$types['text']} NOT NULL,
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
    }
}
