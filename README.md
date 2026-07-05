# AuthKit

Lightweight, extensible PHP 8.1+ authentication library.

- Registration & login with secure password hashing
- Server-side session tokens stored in DB with optional TTL
- Pluggable **login extension pipeline** — deny or challenge after credentials pass
- Multi-step **challenge flows** (MFA, email activation, device check, etc.)
- Pluggable **credential provider** — local PDO default, Cognito/Keycloak as external packages
- Pluggable **password hasher** — `NativePasswordHasher` default
- Pluggable **token transport** — PHP session (web) or Bearer header (API)
- Pluggable **user ID policy** — auto-increment default, UUID in your app
- Lean core schema — `users` table contains only `id`, `email`, `password_hash`; extra columns (e.g. `active`, `suspended_at`) declared by the extensions that need them
- Optional **hooks** for audit and policy (rate-limit, IP checks, logging)
- Admin features: force logout by user / token / email

> Works with MySQL/MariaDB and SQLite. Designed for Slim, Laminas, Symfony or plain PHP.

---

## Installation

```bash
composer require rafalmasiarek/authkit
```

---

## Quick start

```php
use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;

$pdo     = new PDO('sqlite:/path/to/auth.sqlite');
$storage = new PdoUserStorage($pdo);

$auth = new Auth($storage);
$auth->createSchema(); // creates users + sessions tables (+ any registered extension tables)

// Register
$user = $auth->register('user@example.com', 'secret123');

// Login
$login = $auth->login('user@example.com', 'secret123');

if ($login->isSuccess()) {
    // session stored automatically — redirect to dashboard
}

if ($login->isFailure()) {
    echo $login->message(); // "Invalid credentials."
}

// Current user (reads from session)
$user = $auth->getUser(); // ?User

// Logout
$auth->logout();
```

---

## Constructor

```php
new Auth(
    storage:          $storage,           // UserStorageInterface — required
    hook:             null,               // HookInterface|null
    ttlSeconds:       3600,               // int — 0 = no expiry
    messages:         null,               // MessageProviderInterface|null
    throwExceptions:  false,              // bool — throw AuthException on errors
    hasher:           null,               // PasswordHasherInterface|null — default: NativePasswordHasher
    credentials:      null,               // CredentialProviderInterface|null — default: PdoCredentialProvider
    challengeStorage: null,               // ChallengeStorageInterface|null — required for challenge flows
    transport:        null,               // TokenTransportInterface|null — default: PhpSessionTransport
    clock:            null,               // ClockInterface|null — custom clock; takes precedence over timezone
    timezone:         'UTC',             // string — timezone for built-in SystemClock; ignored when clock is provided
)
```

### Clock and timezone

By default Auth uses UTC for all session and challenge expiry calculations.

```php
// Use a specific timezone with the built-in clock:
new Auth($storage, timezone: 'Europe/Warsaw');

// Inject a custom ClockInterface implementation (clock takes precedence over timezone):
new Auth($storage, clock: $appClock);
```

`purgeExpiredTokens()` and `purgeExpired()` are maintenance operations called outside the login flow.
They require an explicit `DateTimeImmutable $now` from the caller:

```php
$storage->purgeExpiredTokens(new \DateTimeImmutable());
$challengeStorage->purgeExpired(new \DateTimeImmutable());
```

---

## Login result

`login()` returns a `Login` object — never throws by default.

```php
$login = $auth->login($email, $password);

$login->isSuccess();        // bool
$login->isFailure();        // bool
$login->requiresChallenge(); // bool — set when an extension requires an extra step

$login->token();            // ?string — session token on success
$login->user();             // ?User   — on success or challenge
$login->message();          // string  — failure reason
$login->challengeToken();   // ?string — raw token for completeChallenge()
$login->challengeType();    // ?string — e.g. 'mfa_totp', 'email_activation'
```

With `throwExceptions: true` — failure throws `AuthException` instead.

---

## Login extension pipeline

Extensions run after credentials are verified. Register them with `addLoginExtension()`.
The first deny or challenge decision terminates the pipeline.

```php
use AuthKit\Extension\LoginExtensionInterface;
use AuthKit\LoginContext;
use AuthKit\LoginDecision;

class ActiveUserExtension implements LoginExtensionInterface
{
    public function decide(LoginContext $context): LoginDecision
    {
        if (!$context->user->get('active')) {
            return LoginDecision::deny('Account is not active.');
        }
        return LoginDecision::allow();
    }
}

$auth->addLoginExtension(new ActiveUserExtension());
```

`LoginContext` carries `$user`, `$ip`, `$userAgent`, and `$credentialMeta` from the credential provider.
Use `$context->credential('key')` to read individual metadata values.

---

## Challenge flows (MFA, email activation, etc.)

An extension can require a second step instead of immediately allowing or denying.

```php
use AuthKit\Extension\ChallengeExtensionInterface;
use AuthKit\Challenge\ChallengeRecord;

class TotpExtension implements ChallengeExtensionInterface
{
    public function decide(LoginContext $context): LoginDecision
    {
        // trigger challenge — payload stored in ChallengeRecord
        return LoginDecision::challenge('mfa_totp', [
            'secret' => $context->user->get('totp_secret'),
        ]);
    }

    public function supportsChallenge(string $type): bool
    {
        return $type === 'mfa_totp';
    }

    public function completeChallenge(ChallengeRecord $challenge, array $input): LoginDecision
    {
        $valid = verify_totp($challenge->payload['secret'], $input['code'] ?? '');
        return $valid ? LoginDecision::allow() : LoginDecision::deny('Invalid code.');
    }
}
```

**Flow:**

```php
// Step 1 — login
$login = $auth->login($email, $password);

if ($login->requiresChallenge()) {
    $_SESSION['challenge_token'] = $login->challengeToken();
    // redirect to /login/mfa — type is $login->challengeType()
}

// Step 2 — verify code
$login = $auth->completeChallenge($_SESSION['challenge_token'], ['code' => $input['code']]);

if ($login->isSuccess()) {
    // session created — redirect to dashboard
}
```

Challenge storage must be provided:

```php
use AuthKit\Challenge\PdoChallengeStorage;

$auth = new Auth($storage, challengeStorage: new PdoChallengeStorage($pdo));
```

Schema: `(new PdoChallengeStorage($pdo))->createSchema();`

---

## Token transport

Controls how the active token is stored and read on the client side.

### Web (default — PHP session)

```php
use AuthKit\Transport\PhpSessionTransport;

$auth = new Auth($storage); // PhpSessionTransport('auth_token') used by default

// Custom session key:
$auth = new Auth($storage, transport: new PhpSessionTransport('my_session_key'));
```

### API (Bearer header)

```php
use AuthKit\Transport\BearerHeaderTransport;

$auth = new Auth($storage, transport: new BearerHeaderTransport());

// Login — return token to client in JSON
$login = $auth->login($email, $password);
// → ['token' => $login->token()]

// Subsequent requests — client sends Authorization: Bearer <token>
$user = $auth->getUser(); // reads from header automatically
```

---

## External credential providers

AuthKit core authenticates into a local `User` object. External providers such as Cognito, Keycloak, Authentik or Zitadel should map their external identity to a local user inside their own `CredentialProviderInterface` implementation.

A credential provider may pass non-persistent login metadata through `CredentialResult::success($user, $meta)`. This metadata is available in login extensions through `LoginContext::credential()`.

```php
// Inside a custom CognitoCredentialProvider::verify():
return CredentialResult::success($localUser, [
    'provider'       => 'cognito',
    'subject'        => $claims['sub'],
    'groups'         => $claims['cognito:groups'] ?? [],
    'email_verified' => $claims['email_verified'] ?? false,
]);

// Inside a login extension:
public function decide(LoginContext $ctx): LoginDecision
{
    if ($ctx->credential('email_verified') === false) {
        return LoginDecision::deny('Email address is not verified.');
    }
    return LoginDecision::allow();
}
```

Users authenticated via an external provider have no local password — `password_hash` is `NULL` in the database. `PdoCredentialProvider` explicitly rejects login attempts for such accounts.

This keeps AuthKit core storage-agnostic and avoids forcing external identity tables into the base package.

---

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

---

## Hooks

All hook methods are optional — implement only what you need.

```php
interface HookInterface {
    public function onBeforeRegister(string $email, string $password, array $fields): true|string;
    public function onRegisterSuccess(User $user): void;
    public function onRegisterFailure(string $email, \Throwable $e): void;

    public function onBeforeLogin(User $user): true|string;
    public function onLoginSuccess(User $user): void;
    public function onLoginFailure(string $email, AuthException $e): void;

    public function onLogout(User $user): void;
    public function onLogoutExpired(): void;
    public function onUserActive(User $user): void;
    public function onUserUpdated(User $user, array $changedFields): void;

    public function onLogoutForced(int|string $userId, ?string $reason, int $count): void;
}
```

---

## Force logout

```php
$auth->forceLogoutUser($userOrId);        // all sessions for a user
$auth->forceLogoutEmail($email);          // all sessions by email
$auth->forceLogoutToken($token);          // single session by token
```

---

## v2.1.0 breaking changes

- `createSchema()` no longer creates an `active` column on the `users` table. The column is only meaningful when `ActiveUserExtension` (or a custom equivalent) is registered — declare it there via `additionalSchema()`. If you relied on the column being present without using the extension, add it yourself with `ALTER TABLE users ADD COLUMN IF NOT EXISTS active TINYINT NOT NULL DEFAULT 0`.

---

## v2 breaking changes

- `Auth::login()` now returns `AuthKit\Login`, not `?string`. Check `$login->isSuccess()`, `$login->isFailure()`, or `$login->requiresChallenge()`.
- Login-time `$additionalChecks` parameter removed from `login()`. Use `LoginExtensionInterface` instead.
- `UserStorageInterface::findByToken()` requires a second argument: `findByToken(string $token, \DateTimeImmutable $now)`.
- `ChallengeStorageInterface::complete()` requires a second argument: `complete(ChallengeRecord $record, \DateTimeImmutable $now)`.
- `ChallengeStorageInterface::purgeExpired()` requires `\DateTimeImmutable $now`.
- `PdoUserStorage::purgeExpiredTokens()` requires `\DateTimeImmutable $now`.
- `UserStorageInterface::storeToken()` accepts `?\DateTimeInterface` instead of `?\DateTime`.
- `Auth` constructor: `$sessionKey` parameter removed — use `transport: new PhpSessionTransport('key')` instead.
- `Auth::setSessionKey()` removed.
- PHP `>=8.1` required (was `>=8.0`).
- `ramsey/uuid` dependency removed — tokens are generated with `bin2hex(random_bytes(32))`.

---

## License

MIT
