# AuthKit

AuthKit is a lightweight, extensible PHP authentication library with:

- Registration & login with secure password hashing
- Server-side **session tokens** (UUID) stored in DB with optional **TTL**
- Pluggable storage backend (**PDO** reference implementation)
- Optional **hooks** for policy & audit (rate-limit, IP checks, logging, etc.)
- Flexible user model (`User::get($fields)` / `User::getAll()`); helpers `getId()`, `getEmail()`
- Admin features: **force logout** by user/token/email

> Designed for apps using Slim/Laminas/Symfony or plain PHP. Works with SQLite and MySQL.

---

## 🚀 Installation

```bash
composer require rafalmasiarek/authkit
```

Your `composer.json` should map:

```json
{
  "autoload": {
    "psr-4": {
      "AuthKit\\": "src/"
    }
  }
}
```

Then:

```bash
composer dump-autoload -o
```

---

## 💾 Storage Model (Users & Sessions)

AuthKit separates **users** from **sessions**. After a successful login, a random **UUID v4 token** is generated and stored in the `sessions` table. The token may have an **expiration** (`expires_at`, optional). The token is also saved in `$_SESSION[$sessionKey]` (default `auth_token`) to reference the DB session.

### Tables

- `users` — app user records (email, password hash, flags, custom fields)
- `sessions` — active logins (user_id, token, created_at, expires_at, optional IP/UA)

You’ll need both tables.

---

### SQLite DDL

```sql
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TRIGGER IF NOT EXISTS users_updated_at
AFTER UPDATE ON users
BEGIN
    UPDATE users SET updated_at = datetime('now') WHERE id = NEW.id;
END;

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,        -- UUID v4
    ip TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NULL,              -- NULL = no expiry
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);
```

---

### MySQL DDL

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(190) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token CHAR(36) NOT NULL UNIQUE,          -- UUID v4
  ip VARCHAR(45) NULL,                     -- IPv4/IPv6
  user_agent TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  CONSTRAINT fk_sessions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_sessions_expires_at ON sessions(expires_at);
```

---

## 🧩 Storage Contract (PDO reference)

```php
interface UserStorageInterface {
    public function findByEmail(string $email): ?\AuthKit\User;
    public function findByToken(string $token): ?\AuthKit\User;
    public function createUser(string $email, string $passwordHash, array $fields = []): \AuthKit\User;
    public function updateUser(\AuthKit\User $user, array $updates): \AuthKit\User;

    public function storeToken(\AuthKit\User $user, string $token, ?DateTime $expiresAt): void;
    public function deleteToken(string $token): int;

    public function deleteTokensByUserId(int $userId): int;
    // Optional: delete all except current
    // public function deleteTokensByUserIdExcept(int $userId, string $exceptToken): int;
}
```

---

## 🔧 Bootstrapping

```php
use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;

$pdo = new PDO('sqlite:/path/to/authkit.sqlite');
$storage = new PdoUserStorage($pdo);

// TTL semantics: 3600 = 1 hour; 0 = no expiry
$auth = new Auth($storage, hook: null, ttlSeconds: 3600);

// Optionally:
$auth->setSessionKey('auth_token');
$auth->setThrowExceptions(false);
```

---

## 🧠 API

### Register

```php
register(string $email, string $password, array $customFields = [], array $additionalChecks = []): User|string|null
```

### Login

```php
login(string $email, string $password, array $additionalChecks = []): ?string
```

### Current User

```php
getUser(): ?User
isLoggedIn(): bool
```

### Logout

```php
logout(): void
```

### Force Logout

```php
forceLogoutUser(User|int $userOrId, ?string $reason = null): int
forceLogoutEmail(string $email, ?string $reason = null): int
forceLogoutToken(string $token, ?string $reason = null): int
```

---

## 🪝 Hooks

```php
interface HookInterface {
    public function onBeforeRegister(string $email, string $password, array $fields): true|string;
    public function onRegisterSuccess(User $user): void;
    public function onRegisterFailure(string $email, \Throwable $e): void;

    public function onBeforeLogin(User $user): true|string;
    public function onLoginSuccess(User $user): void;
    public function onLoginFailure(string $email, \AuthKit\Exception\AuthException $e): void;

    public function onLogout(User $user): void;
    public function onLogoutExpired(): void;
    public function onUserActive(User $user): void;
    public function onUserUpdated(User $user, array $changedFields): void;

    public function onLogoutForced(int $userId, ?string $reason, int $count): void;
}
```

---

## 📦 Examples

A runnable demo with SQLite forms is under `examples/sqlite-forms`:

```
examples/sqlite-forms/
├── bootstrap.php
├── schema.sql
├── index.php
├── account.php
├── logout.php
└── admin.php
```

Run:

```bash
php -S 127.0.0.1:8080 -t examples/sqlite-forms
```

The example will create `authkit.sqlite` on first run.

---

## License

MIT
