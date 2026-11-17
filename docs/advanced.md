## Storage schema

`PdoUserStorage::createSchema()` creates the `users` and `sessions` tables.
`PdoChallengeStorage::createSchema()` creates `auth_challenges` (only needed for challenge flows).

Both support MySQL/MariaDB and SQLite.

### Auth::createSchema() — preferred bootstrap entry point

`Auth::createSchema()` is the single call to initialise the full schema. It:

1. Calls `PdoUserStorage::createSchema()` with the correct column types for the configured ID policy.
2. Runs `additionalSchema()` for every registered login extension that implements `SchemaProviderInterface`.
3. Accepts extra `SchemaProviderInterface` instances for non-extension components (e.g. a mailer).

```php
$auth = new Auth(new PdoUserStorage($pdo, new UuidUserIdPolicy()));
$auth->addLoginExtension(new SuspendedUserExtension()); // may declare a suspended_at column
$auth->addLoginExtension(new ActiveUserExtension());    // declares user_activations table
$auth->createSchema($mailer);                           // mailer declares mail_tracking table
```

`$storage->createSchema()` still works for simple bootstraps without extensions.

### User ID policy

`UserIdPolicyInterface` controls both ID generation and the schema column types used by `createSchema()`.

| Method | Purpose |
|--------|---------|
| `generate()` | Returns the ID to insert, or `null` to let the database auto-increment. |
| `idColumnDefinition(string $driver)` | Full inline column definition for `users.id`. Drives `PRIMARY KEY` placement. |
| `userIdForeignType(string $driver)` | SQL type for `sessions.user_id` and any other FK referencing `users.id`. |

**`NullUserIdPolicy` (default)** — delegates to the database:

| Driver | `users.id` | `sessions.user_id` |
|--------|-----------|-------------------|
| MySQL  | `INT NOT NULL AUTO_INCREMENT` | `INT NOT NULL` |
| SQLite | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INTEGER NOT NULL` |

**Custom policy (e.g. UUID):**

```php
use AuthKit\UserId\UserIdPolicyInterface;

final class UuidUserIdPolicy implements UserIdPolicyInterface
{
    public function generate(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public function idColumnDefinition(string $driver): string
    {
        return 'CHAR(36) NOT NULL'; // createSchema() adds PRIMARY KEY (id) separately
    }

    public function userIdForeignType(string $driver): string
    {
        return 'CHAR(36) NOT NULL';
    }
}

$storage = new PdoUserStorage($pdo, new UuidUserIdPolicy());
$auth    = new Auth($storage);
$auth->createSchema(); // users.id is CHAR(36), sessions.user_id is CHAR(36)
```

### Extension schema — SchemaProviderInterface

Any class can declare the tables or columns it needs by implementing `SchemaProviderInterface`.
`Auth::createSchema()` automatically picks it up from registered login extensions and any explicitly
passed providers.

```php
use AuthKit\Extension\LoginExtensionInterface;
use AuthKit\Extension\SchemaProviderInterface;
use AuthKit\LoginContext;
use AuthKit\LoginDecision;

final class BruteForceExtension implements LoginExtensionInterface, SchemaProviderInterface
{
    public function decide(LoginContext $context): LoginDecision
    {
        // check login_attempts and ip_bans tables …
        return LoginDecision::allow();
    }

    public function additionalSchema(string $driver): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id           INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip           VARCHAR(45) NOT NULL,
                email        VARCHAR(255) NOT NULL,
                attempted_at DATETIME    NOT NULL DEFAULT NOW(),
                INDEX idx_ip_time (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS ip_bans (
                id         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip         VARCHAR(45) NOT NULL UNIQUE,
                reason     VARCHAR(255) NOT NULL,
                banned_at  DATETIME    NOT NULL DEFAULT NOW(),
                expires_at DATETIME    NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}

// Bootstrap — one call creates everything:
$auth->addLoginExtension(new BruteForceExtension($pdo));
$auth->createSchema(); // creates users, sessions, login_attempts, ip_bans
```

Rules for `additionalSchema()`:
- Every statement **must be idempotent** — `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE … ADD COLUMN IF NOT EXISTS`, etc.
- The method receives the PDO driver name (`'mysql'`, `'sqlite'`, …) so you can emit driver-specific SQL.
- Statements run after the core `users` and `sessions` tables are created.

### External users and nullable password_hash

`password_hash` is `NULL` in the default schema. `PdoCredentialProvider` treats a `NULL` or empty hash
as a disabled local login and returns a failure — this prevents accidental password bypass.

`PdoUserStorage::createUser()` is intended for local password accounts and requires a non-empty password hash.
External credential providers (Cognito, Keycloak, etc.) should manage user creation with their own logic,
or insert directly with `password_hash = NULL` for users that only authenticate externally.

