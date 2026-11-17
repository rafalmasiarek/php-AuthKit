# php-AuthKit
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



## License
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

```text
The MIT License (MIT)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files, to deal in the Software
without restriction.

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.

Source: https://opensource.org/licenses/MIT
```
See [LICENSE](LICENSE) for full details.
## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [email](mailto:rafal@masiarek.pl) | [github](https://github.com/rafalmasiarek)


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



## v2.1.0 breaking changes

- `createSchema()` no longer creates an `active` column on the `users` table.

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



## Index

### Classes

- [`Auth`](#auth) — `AuthKit`
- [`ChallengeRecord`](#challengerecord) — `AuthKit\Challenge`
- [`PdoChallengeStorage`](#pdochallengestorage) — `AuthKit\Challenge`
- [`SystemClock`](#systemclock) — `AuthKit\Clock`
- [`CredentialResult`](#credentialresult) — `AuthKit\Credential`
- [`PdoCredentialProvider`](#pdocredentialprovider) — `AuthKit\Credential`
- [`AuthException`](#authexception) — `AuthKit\Exception`
- [`AbstractHook`](#abstracthook) — `AuthKit\Hook`
- [`Login`](#login) — `AuthKit`
- [`LoginContext`](#logincontext) — `AuthKit`
- [`LoginDecision`](#logindecision) — `AuthKit`
- [`DefaultMessageProvider`](#defaultmessageprovider) — `AuthKit\Message`
- [`NativePasswordHasher`](#nativepasswordhasher) — `AuthKit\Password`
- [`PdoUserStorage`](#pdouserstorage) — `AuthKit\Storage`
- [`BearerHeaderTransport`](#bearerheadertransport) — `AuthKit\Transport`
- [`PhpSessionTransport`](#phpsessiontransport) — `AuthKit\Transport`
- [`User`](#user) — `AuthKit`
- [`NullUserIdPolicy`](#nulluseridpolicy) — `AuthKit\UserId`

### Interfaces

- [`ChallengeStorageInterface`](#challengestorageinterface) — `AuthKit\Challenge`
- [`ClockInterface`](#clockinterface) — `AuthKit\Clock`
- [`CredentialProviderInterface`](#credentialproviderinterface) — `AuthKit\Credential`
- [`ChallengeExtensionInterface`](#challengeextensioninterface) — `AuthKit\Extension`
- [`LoginExtensionInterface`](#loginextensioninterface) — `AuthKit\Extension`
- [`SchemaProviderInterface`](#schemaproviderinterface) — `AuthKit\Extension`
- [`HookInterface`](#hookinterface) — `AuthKit\Hook`
- [`MessageProviderInterface`](#messageproviderinterface) — `AuthKit\Message`
- [`PasswordHasherInterface`](#passwordhasherinterface) — `AuthKit\Password`
- [`SchemaMigrationInterface`](#schemamigrationinterface) — `AuthKit\Storage`
- [`UserStorageInterface`](#userstorageinterface) — `AuthKit\Storage`
- [`TokenTransportInterface`](#tokentransportinterface) — `AuthKit\Transport`
- [`UserIdPolicyInterface`](#useridpolicyinterface) — `AuthKit\UserId`

## `Auth`

*final class* — `AuthKit\Auth` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L36)

Core authentication facade. Handles registration, login (credential verification + extension pipeline), session lifecycle, and optional multi-step challenge flows (MFA, email activation, etc.).

**Depends on:** [`UserStorageInterface`](#userstorageinterface), [`HookInterface`](#hookinterface), [`MessageProviderInterface`](#messageproviderinterface), [`PasswordHasherInterface`](#passwordhasherinterface), [`CredentialProviderInterface`](#credentialproviderinterface), [`ChallengeStorageInterface`](#challengestorageinterface), [`TokenTransportInterface`](#tokentransportinterface), [`ClockInterface`](#clockinterface)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$throwExceptions` | `bool` | private | Whether AuthException is thrown on errors instead of returning a message. |
| `$messages` | [`MessageProviderInterface`](#messageproviderinterface) | private | — |
| `$hasher` | [`PasswordHasherInterface`](#passwordhasherinterface) | private | Used by register() to hash new passwords. |
| `$credentials` | [`CredentialProviderInterface`](#credentialproviderinterface) | private | Used by login() to verify credentials. |
| `$transport` | [`TokenTransportInterface`](#tokentransportinterface) | private | Handles reading and writing the active token. |
| `$clock` | [`ClockInterface`](#clockinterface) | private | Used for session and challenge expiry calculations. |
| `$loginExtensions` | `array<LoginExtensionInterface>` | private | Evaluated in order after credentials pass. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#auth-__construct) | public | — | — |
| [`addLoginExtension()`](#auth-addloginextension) | public | `void` | Register a login extension to be evaluated after credentials pass. Extensions are evaluated in registration order. The first deny or challenge decision terminates the pipeline. |
| [`createSchema()`](#auth-createschema) | public | `void` | Bootstrap the database schema for the configured storage backend. Delegates to the storage layer if it implements SchemaMigrationInterface. Schema providers are collected from two sources: 1. Registered login extensions that implement SchemaProviderInterface (e.g. SuspendedUserExtension, ActiveUserExtension). 2. Any additional providers passed explicitly — use this for components that are not login extensions but own database tables (e.g. Mailer). Does nothing when the active storage does not support schema migration (e.g. an in-memory test stub). Typical bootstrap sequence: $auth = new Auth(new PdoUserStorage($pdo, new UuidUserIdPolicy()), ...); $auth->addLoginExtension(new SuspendedUserExtension()); $auth->addLoginExtension(new ActiveUserExtension()); $auth->createSchema($mailer); // extensions + Mailer mail_tracking table |
| [`setThrowExceptions()`](#auth-setthrowexceptions) | public | `void` | Set whether AuthException is thrown on authentication errors. |
| [`register()`](#auth-register) | public | `User\|string\|null` | Register a new user. |
| [`login()`](#auth-login) | public | [`Login`](#login) | Verify credentials and run the login extension pipeline. Returns a Login in one of three states: - success : session created, token available. - failure : credentials invalid or an extension denied. - challengeRequired : credentials valid but an extension requires an extra step. |
| [`completeChallenge()`](#auth-completechallenge) | public | [`Login`](#login) | Complete a pending challenge step and create a session on success. |
| [`isLoggedIn()`](#auth-isloggedin) | public | `bool` | Check whether a user is currently logged in. |
| [`getUser()`](#auth-getuser) | public | `User\|null` | Retrieve the currently logged-in user from the active session. |
| [`logout()`](#auth-logout) | public | `void` | Log out the currently logged-in user. |
| [`forceLogoutUser()`](#auth-forcelogoutuser) | public | `int` | Invalidate all sessions for a user across every device. |
| [`forceLogoutToken()`](#auth-forcelogouttoken) | public | `int` | Invalidate a single session by its token. |
| [`forceLogoutEmail()`](#auth-forcelogoutemail) | public | `int` | Invalidate all sessions for a user identified by email. |
| [`updateUser()`](#auth-updateuser) | public | [`User`](#user) | Update fields on a user record. |
| [`createSession()`](#auth-createsession) | private | [`Login`](#login) | Create a session, persist the token, and store it in $_SESSION. |
| [`issueChallenge()`](#auth-issuechallenge) | private | [`Login`](#login) | Store a ChallengeRecord and return a challengeRequired Login. |
| [`findChallengeExtension()`](#auth-findchallengeextension) | private | `ChallengeExtensionInterface\|null` | Find the first registered extension that handles the given challenge type. |
| [`loginFailure()`](#auth-loginfailure) | private | [`Login`](#login) | Return Login::failure() and optionally throw AuthException. |
| [`fail()`](#auth-fail) | private | `string` | Handle register-time failure: invoke hook callback, then throw or return message. |
| [`callHook()`](#auth-callhook) | private | `void` | Call a hook method if the hook is configured and the method exists. |
| [`callHookResult()`](#auth-callhookresult) | private | `mixed` | Call a hook method that returns a pass/fail result. Returns true when no hook is configured (meaning: proceed). |

<details>
<summary><code>__construct()</code></summary>

#### `Auth __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L86)

```php
public function __construct(UserStorageInterface $storage, ?HookInterface $hook, int $ttlSeconds, ?MessageProviderInterface $messages, bool $throwExceptions, ?PasswordHasherInterface $hasher, ?CredentialProviderInterface $credentials, ?ChallengeStorageInterface $challengeStorage, ?TokenTransportInterface $transport, ?ClockInterface $clock, string $timezone)
```

| Param | Type | Description |
|---|---|---|
| `$storage` | [`UserStorageInterface`](#userstorageinterface) | User persistence layer. |
| `$hook` | `HookInterface\|null` | Optional lifecycle hooks. |
| `$ttlSeconds` | `int` | Session TTL in seconds (0 = no expiry). |
| `$messages` | `MessageProviderInterface\|null` | Custom error messages. |
| `$throwExceptions` | `bool` | Throw AuthException on errors. |
| `$hasher` | `PasswordHasherInterface\|null` | Hasher for register() (default: NativePasswordHasher). |
| `$credentials` | `CredentialProviderInterface\|null` | Credential verifier for login() (default: PdoCredentialProvider). |
| `$challengeStorage` | `ChallengeStorageInterface\|null` | Required only when using challenge extensions. |
| `$transport` | `TokenTransportInterface\|null` | Token read/write transport (default: PhpSessionTransport). |
| `$clock` | `ClockInterface\|null` | Custom clock (takes precedence over $timezone when provided). |
| `$timezone` | `string` | Timezone for the built-in SystemClock (e.g. 'Europe/Warsaw'). Ignored when $clock is provided. Defaults to UTC. |

</details>

<details>
<summary><code>addLoginExtension()</code></summary>

#### `Auth addLoginExtension()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L117)

```php
public function addLoginExtension(LoginExtensionInterface $extension): void
```

Register a login extension to be evaluated after credentials pass. Extensions are evaluated in registration order. The first deny or challenge decision terminates the pipeline.

| Param | Type | Description |
|---|---|---|
| `$extension` | [`LoginExtensionInterface`](#loginextensioninterface) | — |

</details>

<details>
<summary><code>createSchema()</code></summary>

#### `Auth createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L147)

```php
public function createSchema(SchemaProviderInterface $additionalProviders): void
```

Bootstrap the database schema for the configured storage backend. Delegates to the storage layer if it implements SchemaMigrationInterface. Schema providers are collected from two sources: 1. Registered login extensions that implement SchemaProviderInterface (e.g. SuspendedUserExtension, ActiveUserExtension). 2. Any additional providers passed explicitly — use this for components that are not login extensions but own database tables (e.g. Mailer). Does nothing when the active storage does not support schema migration (e.g. an in-memory test stub). Typical bootstrap sequence: $auth = new Auth(new PdoUserStorage($pdo, new UuidUserIdPolicy()), ...); $auth->addLoginExtension(new SuspendedUserExtension()); $auth->addLoginExtension(new ActiveUserExtension()); $auth->createSchema($mailer); // extensions + Mailer mail_tracking table

| Param | Type | Description |
|---|---|---|
| `$additionalProviders` | [`SchemaProviderInterface`](#schemaproviderinterface) | — |

</details>

<details>
<summary><code>setThrowExceptions()</code></summary>

#### `Auth setThrowExceptions()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L167)

```php
public function setThrowExceptions(bool $value): void
```

Set whether AuthException is thrown on authentication errors.

| Param | Type | Description |
|---|---|---|
| `$value` | `bool` | — |

</details>

<details>
<summary><code>register()</code></summary>

#### `Auth register()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L182)

```php
public function register(string $email, string $password, array $customFields, array $additionalChecks): User|string|null
```

Register a new user.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | User's email address. |
| `$password` | `string` | Plain-text password. |
| `$customFields` | `array<string, mixed>` | Additional fields to persist. |
| `$additionalChecks` | `array<callable>` | Legacy pre-registration callbacks. |

**Returns:** `User\|string\|null` — Created User on success, or an error message on failure.

| Throws | When |
|---|---|
| [`AuthException`](#authexception) | When $throwExceptions is true and an error occurs. |

</details>

<details>
<summary><code>login()</code></summary>

#### `Auth login()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L229)

```php
public function login(string $email, string $password): Login
```

Verify credentials and run the login extension pipeline. Returns a Login in one of three states: - success        : session created, token available. - failure        : credentials invalid or an extension denied. - challengeRequired : credentials valid but an extension requires an extra step.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | User's email address. |
| `$password` | `string` | Plain-text password. |

**Returns:** [`Login`](#login)

| Throws | When |
|---|---|
| [`AuthException`](#authexception) | When $throwExceptions is true and login is rejected. |

</details>

<details>
<summary><code>completeChallenge()</code></summary>

#### `Auth completeChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L287)

```php
public function completeChallenge(string $challengeToken, array $input): Login
```

Complete a pending challenge step and create a session on success.

| Param | Type | Description |
|---|---|---|
| `$challengeToken` | `string` | Raw token stored client-side (e.g. $_SESSION['authkit_challenge']). |
| `$input` | `array<string, mixed>` | User-supplied verification data. |

**Returns:** [`Login`](#login)

| Throws | When |
|---|---|
| [`AuthException`](#authexception) | When $throwExceptions is true and verification fails. |
| `\RuntimeException` | When no ChallengeStorageInterface is configured. |

</details>

<details>
<summary><code>isLoggedIn()</code></summary>

#### `Auth isLoggedIn()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L333)

```php
public function isLoggedIn(): bool
```

Check whether a user is currently logged in.

**Returns:** `bool`

</details>

<details>
<summary><code>getUser()</code></summary>

#### `Auth getUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L343)

```php
public function getUser(): ?User
```

Retrieve the currently logged-in user from the active session.

**Returns:** `User\|null`

</details>

<details>
<summary><code>logout()</code></summary>

#### `Auth logout()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L369)

```php
public function logout(): void
```

Log out the currently logged-in user.

</details>

<details>
<summary><code>forceLogoutUser()</code></summary>

#### `Auth forceLogoutUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L392)

```php
public function forceLogoutUser(User|int|string $userOrId, ?string $reason): int
```

Invalidate all sessions for a user across every device.

| Param | Type | Description |
|---|---|---|
| `$userOrId` | `User\|int\|string` | User object or ID. |
| `$reason` | `string\|null` | Optional audit reason passed to the hook. |

**Returns:** `int` — Number of invalidated sessions.

</details>

<details>
<summary><code>forceLogoutToken()</code></summary>

#### `Auth forceLogoutToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L415)

```php
public function forceLogoutToken(string $token, ?string $reason): int
```

Invalidate a single session by its token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | Session token to revoke. |
| `$reason` | `string\|null` | Optional audit reason. |

**Returns:** `int` — 1 if removed, 0 otherwise.

</details>

<details>
<summary><code>forceLogoutEmail()</code></summary>

#### `Auth forceLogoutEmail()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L442)

```php
public function forceLogoutEmail(string $email, ?string $reason): int
```

Invalidate all sessions for a user identified by email.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$reason` | `string\|null` | Optional audit reason. |

**Returns:** `int` — Number of invalidated sessions.

</details>

<details>
<summary><code>updateUser()</code></summary>

#### `Auth updateUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L456)

```php
public function updateUser(User $user, array $updates): User
```

Update fields on a user record.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | User to update. |
| `$updates` | `array<string, mixed>` | Fields to update. |

**Returns:** [`User`](#user) — Updated user.

</details>

<details>
<summary><code>createSession()</code></summary>

#### `Auth createSession()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L474)

```php
private function createSession(User $user): Login
```

Create a session, persist the token, and store it in $_SESSION.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

**Returns:** [`Login`](#login)

</details>

<details>
<summary><code>issueChallenge()</code></summary>

#### `Auth issueChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L498)

```php
private function issueChallenge(User $user, LoginContext $context, LoginDecision $decision): Login
```

Store a ChallengeRecord and return a challengeRequired Login.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |
| `$context` | [`LoginContext`](#logincontext) | — |
| `$decision` | [`LoginDecision`](#logindecision) | — |

**Returns:** [`Login`](#login)

| Throws | When |
|---|---|
| `\RuntimeException` | When no ChallengeStorageInterface is configured. |

</details>

<details>
<summary><code>findChallengeExtension()</code></summary>

#### `Auth findChallengeExtension()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L535)

```php
private function findChallengeExtension(string $type): ?ChallengeExtensionInterface
```

Find the first registered extension that handles the given challenge type.

| Param | Type | Description |
|---|---|---|
| `$type` | `string` | — |

**Returns:** `ChallengeExtensionInterface\|null`

</details>

<details>
<summary><code>loginFailure()</code></summary>

#### `Auth loginFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L553)

```php
private function loginFailure(string $message): Login
```

Return Login::failure() and optionally throw AuthException.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | — |

**Returns:** [`Login`](#login)

| Throws | When |
|---|---|
| [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>fail()</code></summary>

#### `Auth fail()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L570)

```php
private function fail(string $message, ?callable $hookCallback): string
```

Handle register-time failure: invoke hook callback, then throw or return message.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | — |
| `$hookCallback` | `callable\|null` | — |

**Returns:** `string`

| Throws | When |
|---|---|
| [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>callHook()</code></summary>

#### `Auth callHook()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L590)

```php
private function callHook(string $method, mixed $args): void
```

Call a hook method if the hook is configured and the method exists.

| Param | Type | Description |
|---|---|---|
| `$method` | `string` | — |
| `$args` | `mixed` | — |

</details>

<details>
<summary><code>callHookResult()</code></summary>

#### `Auth callHookResult()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Auth.php#L606)

```php
private function callHookResult(string $method, mixed $args): mixed
```

Call a hook method that returns a pass/fail result. Returns true when no hook is configured (meaning: proceed).

| Param | Type | Description |
|---|---|---|
| `$method` | `string` | — |
| `$args` | `mixed` | — |

**Returns:** `mixed`

</details>

## `ChallengeRecord`

*final class* — `AuthKit\Challenge\ChallengeRecord` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeRecord.php#L18)

Immutable value object representing a persisted challenge step. Created when a LoginExtensionInterface returns LoginDecision::challenge(). The raw token travels exclusively on the client side; only its sha256 hash is stored in the database.

**Depends on:** `DateTime`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#challengerecord-__construct) | public | — | — |
| [`isExpired()`](#challengerecord-isexpired) | public | `bool` | — |
| [`isExhausted()`](#challengerecord-isexhausted) | public | `bool` | — |
| [`isCompleted()`](#challengerecord-iscompleted) | public | `bool` | — |

<details>
<summary><code>__construct()</code></summary>

#### `ChallengeRecord __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeRecord.php#L32)

```php
public function __construct(string $id, int|string $userId, string $type, array $payload, int $maxAttempts, DateTime $expiresAt, string $ip, string $userAgent, int $attempts, ?DateTime $completedAt)
```

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | UUID v4 record identifier. |
| `$userId` | `int\|string` | ID of the user being challenged. |
| `$type` | `string` | Challenge type handled by a ChallengeExtensionInterface. |
| `$payload` | `array` | — |
| `$maxAttempts` | `int` | Maximum allowed verification attempts before exhaustion. |
| `$expiresAt` | `DateTime` | Point in time when this challenge becomes invalid. |
| `$ip` | `string` | Client IP at time of challenge creation. |
| `$userAgent` | `string` | Client User-Agent at time of challenge creation. |
| `$attempts` | `int` | Current failed attempt count (mutable after hydration). |
| `$completedAt` | `DateTime\|null` | When the challenge was successfully completed (mutable). |

</details>

<details>
<summary><code>isExpired()</code></summary>

#### `ChallengeRecord isExpired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeRecord.php#L49)

```php
public function isExpired(): bool
```

**Returns:** `bool` — True when the challenge has passed its expiry time.

</details>

<details>
<summary><code>isExhausted()</code></summary>

#### `ChallengeRecord isExhausted()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeRecord.php#L57)

```php
public function isExhausted(): bool
```

**Returns:** `bool` — True when the maximum attempt count has been reached.

</details>

<details>
<summary><code>isCompleted()</code></summary>

#### `ChallengeRecord isCompleted()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeRecord.php#L65)

```php
public function isCompleted(): bool
```

**Returns:** `bool` — True when the challenge has been successfully completed.

</details>

## `ChallengeStorageInterface`

*interface* — `AuthKit\Challenge\ChallengeStorageInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L15)

Persists and retrieves challenge records for multi-step login flows. Only the sha256 hash of the raw token is stored — the raw token travels exclusively on the client side (e.g. $_SESSION['authkit_challenge']).

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`store()`](#challengestorageinterface-store) | public | `void` | Persist a new challenge record. |
| [`findByToken()`](#challengestorageinterface-findbytoken) | public | `ChallengeRecord\|null` | Find a non-completed challenge by its raw token. |
| [`complete()`](#challengestorageinterface-complete) | public | `void` | Mark a challenge as successfully completed. The implementation must record $now as the completion timestamp. Pass Auth::now() or an equivalent value from your clock to keep timestamps consistent with the rest of the auth layer. |
| [`incrementAttempts()`](#challengestorageinterface-incrementattempts) | public | `void` | Increment the failed attempt counter for a challenge. |
| [`purgeExpired()`](#challengestorageinterface-purgeexpired) | public | `void` | Delete all expired challenge records. The caller is responsible for supplying the reference time. Typically called from a maintenance job or cron — not part of the login flow. |
| [`createSchema()`](#challengestorageinterface-createschema) | public | `void` | Create the auth_challenges table if it does not exist. |

<details>
<summary><code>store()</code></summary>

#### `ChallengeStorageInterface store()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L24)

```php
public function store(ChallengeRecord $record, string $rawToken): void
```

Persist a new challenge record.

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | Challenge data to persist. |
| `$rawToken` | `string` | Raw token sent to the client (sha256 hash stored in DB). |

</details>

<details>
<summary><code>findByToken()</code></summary>

#### `ChallengeStorageInterface findByToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L32)

```php
public function findByToken(string $rawToken): ?ChallengeRecord
```

Find a non-completed challenge by its raw token.

| Param | Type | Description |
|---|---|---|
| `$rawToken` | `string` | The raw token held by the client. |

**Returns:** `ChallengeRecord\|null` — Null when not found or already completed.

</details>

<details>
<summary><code>complete()</code></summary>

#### `ChallengeStorageInterface complete()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L45)

```php
public function complete(ChallengeRecord $record, \DateTimeImmutable $now): void
```

Mark a challenge as successfully completed. The implementation must record $now as the completion timestamp. Pass Auth::now() or an equivalent value from your clock to keep timestamps consistent with the rest of the auth layer.

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | Challenge to mark as completed. |
| `$now` | `\DateTimeImmutable` | Current time used as the completed_at timestamp. |

</details>

<details>
<summary><code>incrementAttempts()</code></summary>

#### `ChallengeStorageInterface incrementAttempts()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L53)

```php
public function incrementAttempts(ChallengeRecord $record): void
```

Increment the failed attempt counter for a challenge.

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | — |

</details>

<details>
<summary><code>purgeExpired()</code></summary>

#### `ChallengeStorageInterface purgeExpired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L64)

```php
public function purgeExpired(\DateTimeImmutable $now): void
```

Delete all expired challenge records. The caller is responsible for supplying the reference time. Typically called from a maintenance job or cron — not part of the login flow.

| Param | Type | Description |
|---|---|---|
| `$now` | `\DateTimeImmutable` | Records with expires_at before this value will be deleted. |

</details>

<details>
<summary><code>createSchema()</code></summary>

#### `ChallengeStorageInterface createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/ChallengeStorageInterface.php#L71)

```php
public function createSchema(): void
```

Create the auth_challenges table if it does not exist.

</details>

## `PdoChallengeStorage`

*final class* — `AuthKit\Challenge\PdoChallengeStorage` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L18)

PDO-backed challenge storage supporting MySQL/MariaDB and SQLite. Token security: only sha256($rawToken) is stored. The raw token is returned to the caller and kept client-side. A DB leak cannot be used to replay challenges.

implements [`ChallengeStorageInterface`](#challengestorageinterface)

**Depends on:** `PDO`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#pdochallengestorage-__construct) | public | — | — |
| [`store()`](#pdochallengestorage-store) | public | `void` | — |
| [`findByToken()`](#pdochallengestorage-findbytoken) | public | ?[`ChallengeRecord`](#challengerecord) | — |
| [`complete()`](#pdochallengestorage-complete) | public | `void` | — |
| [`incrementAttempts()`](#pdochallengestorage-incrementattempts) | public | `void` | — |
| [`purgeExpired()`](#pdochallengestorage-purgeexpired) | public | `void` | — |
| [`createSchema()`](#pdochallengestorage-createschema) | public | `void` | — |
| [`hydrate()`](#pdochallengestorage-hydrate) | private | [`ChallengeRecord`](#challengerecord) | Hydrate a database row into a ChallengeRecord. |

<details>
<summary><code>__construct()</code></summary>

#### `PdoChallengeStorage __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L23)

```php
public function __construct(PDO $pdo)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Database connection. |

</details>

<details>
<summary><code>store()</code></summary>

#### `PdoChallengeStorage store()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L30)

```php
public function store(ChallengeRecord $record, string $rawToken): void
```

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | — |
| `$rawToken` | `string` | — |

</details>

<details>
<summary><code>findByToken()</code></summary>

#### `PdoChallengeStorage findByToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L55)

```php
public function findByToken(string $rawToken): ?ChallengeRecord
```

| Param | Type | Description |
|---|---|---|
| `$rawToken` | `string` | — |

**Returns:** ?[`ChallengeRecord`](#challengerecord)

</details>

<details>
<summary><code>complete()</code></summary>

#### `PdoChallengeStorage complete()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L71)

```php
public function complete(ChallengeRecord $record, \DateTimeImmutable $now): void
```

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | — |
| `$now` | `\DateTimeImmutable` | — |

</details>

<details>
<summary><code>incrementAttempts()</code></summary>

#### `PdoChallengeStorage incrementAttempts()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L81)

```php
public function incrementAttempts(ChallengeRecord $record): void
```

| Param | Type | Description |
|---|---|---|
| `$record` | [`ChallengeRecord`](#challengerecord) | — |

</details>

<details>
<summary><code>purgeExpired()</code></summary>

#### `PdoChallengeStorage purgeExpired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L91)

```php
public function purgeExpired(\DateTimeImmutable $now): void
```

| Param | Type | Description |
|---|---|---|
| `$now` | `\DateTimeImmutable` | — |

</details>

<details>
<summary><code>createSchema()</code></summary>

#### `PdoChallengeStorage createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L101)

```php
public function createSchema(): void
```

</details>

<details>
<summary><code>hydrate()</code></summary>

#### `PdoChallengeStorage hydrate()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Challenge/PdoChallengeStorage.php#L157)

```php
private function hydrate(array $row): ChallengeRecord
```

Hydrate a database row into a ChallengeRecord.

| Param | Type | Description |
|---|---|---|
| `$row` | `array<string, mixed>` | — |

**Returns:** [`ChallengeRecord`](#challengerecord)

</details>

## `ClockInterface`

*interface* — `AuthKit\Clock\ClockInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Clock/ClockInterface.php#L15)

Provides the current time as an immutable value object. Compatible with the PSR-20 clock contract — any PSR-20 implementation satisfies this interface without modification.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`now()`](#clockinterface-now) | public | `\DateTimeImmutable` | Return the current time. |

<details>
<summary><code>now()</code></summary>

#### `ClockInterface now()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Clock/ClockInterface.php#L22)

```php
public function now(): \DateTimeImmutable
```

Return the current time.

**Returns:** `\DateTimeImmutable`

</details>

## `SystemClock`

*final class* — `AuthKit\Clock\SystemClock` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Clock/SystemClock.php#L12)

System clock — returns the current wall-clock time in the configured timezone.

implements [`ClockInterface`](#clockinterface)

**Depends on:** `\DateTimeZone`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tz` | `\DateTimeZone` | private readonly | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#systemclock-__construct) | public | — | — |
| [`now()`](#systemclock-now) | public | `\DateTimeImmutable` | — |

<details>
<summary><code>__construct()</code></summary>

#### `SystemClock __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Clock/SystemClock.php#L24)

```php
public function __construct(\DateTimeZone|string $timezone)
```

| Param | Type | Description |
|---|---|---|
| `$timezone` | `\DateTimeZone\|string` | Timezone for now(). Defaults to UTC. |

| Throws | When |
|---|---|
| `\Exception` | When an invalid timezone string is provided. |

</details>

<details>
<summary><code>now()</code></summary>

#### `SystemClock now()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Clock/SystemClock.php#L32)

```php
public function now(): \DateTimeImmutable
```

**Returns:** `\DateTimeImmutable`

</details>

## `CredentialProviderInterface`

*interface* — `AuthKit\Credential\CredentialProviderInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialProviderInterface.php#L12)

Verifies raw credentials and returns the matching user on success.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`verify()`](#credentialproviderinterface-verify) | public | [`CredentialResult`](#credentialresult) | Verify the supplied credentials. |

<details>
<summary><code>verify()</code></summary>

#### `CredentialProviderInterface verify()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialProviderInterface.php#L21)

```php
public function verify(array $credentials, array $context): CredentialResult
```

Verify the supplied credentials.

| Param | Type | Description |
|---|---|---|
| `$credentials` | `array<string, mixed>` | Raw input (e.g. ['email' => ..., 'password' => ...]). |
| `$context` | `array<string, mixed>` | Optional request context (e.g. 'ip', 'user_agent') for logging or rate-limiting. |

**Returns:** [`CredentialResult`](#credentialresult)

</details>

## `CredentialResult`

*final class* — `AuthKit\Credential\CredentialResult` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L14)

Result of a credential verification attempt.

**Depends on:** [`User`](#user)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#credentialresult-__construct) | private | — | — |
| [`success()`](#credentialresult-success) | public static | `self` | Create a successful result with the verified user. |
| [`failure()`](#credentialresult-failure) | public static | `self` | Create a failure result with a reason message. |
| [`isSuccess()`](#credentialresult-issuccess) | public | `bool` | — |
| [`user()`](#credentialresult-user) | public | `User\|null` | — |
| [`message()`](#credentialresult-message) | public | `string` | — |
| [`meta()`](#credentialresult-meta) | public | `mixed` | Return provider metadata or a single key from it. With no argument, returns the full metadata array. With a key, returns that value or $default when not present. |

<details>
<summary><code>__construct()</code></summary>

#### `CredentialResult __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L22)

```php
private function __construct(bool $success, ?User $user, string $message, array $meta)
```

| Param | Type | Description |
|---|---|---|
| `$success` | `bool` | Whether credentials were valid. |
| `$user` | `User\|null` | Authenticated user, or null on failure. |
| `$message` | `string` | Failure reason, or empty string on success. |
| `$meta` | `array<string, mixed>` | Provider-specific metadata (e.g. provider name, JWT claims). |

</details>

<details>
<summary><code>success()</code></summary>

#### `CredentialResult success()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L37)

```php
public static function success(User $user, array $meta): self
```

Create a successful result with the verified user.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | Authenticated user. |
| `$meta` | `array<string, mixed>` | Optional provider metadata passed to the login extension pipeline. |

**Returns:** `self`

</details>

<details>
<summary><code>failure()</code></summary>

#### `CredentialResult failure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L49)

```php
public static function failure(string $message, array $meta): self
```

Create a failure result with a reason message.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | Human-readable failure reason. |
| `$meta` | `array<string, mixed>` | Optional provider metadata (e.g. for audit logging). |

**Returns:** `self`

</details>

<details>
<summary><code>isSuccess()</code></summary>

#### `CredentialResult isSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L57)

```php
public function isSuccess(): bool
```

**Returns:** `bool` — True when credentials were valid.

</details>

<details>
<summary><code>user()</code></summary>

#### `CredentialResult user()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L65)

```php
public function user(): ?User
```

**Returns:** `User\|null` — Authenticated user, or null on failure.

</details>

<details>
<summary><code>message()</code></summary>

#### `CredentialResult message()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L73)

```php
public function message(): string
```

**Returns:** `string` — Failure reason, or empty string on success.

</details>

<details>
<summary><code>meta()</code></summary>

#### `CredentialResult meta()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/CredentialResult.php#L88)

```php
public function meta(?string $key, mixed $default): mixed
```

Return provider metadata or a single key from it. With no argument, returns the full metadata array. With a key, returns that value or $default when not present.

| Param | Type | Description |
|---|---|---|
| `$key` | `string\|null` | Key to retrieve, or null for the full array. |
| `$default` | `mixed` | Returned when the key is not found. |

**Returns:** `mixed`

</details>

## `PdoCredentialProvider`

*final class* — `AuthKit\Credential\PdoCredentialProvider` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/PdoCredentialProvider.php#L18)

PDO-backed credential provider for email + password authentication. Looks up the user by email, verifies the password via the configured hasher, and automatically re-hashes the stored password if the hasher signals it is outdated.

implements [`CredentialProviderInterface`](#credentialproviderinterface)

**Depends on:** [`UserStorageInterface`](#userstorageinterface), [`PasswordHasherInterface`](#passwordhasherinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#pdocredentialprovider-__construct) | public | — | — |
| [`verify()`](#pdocredentialprovider-verify) | public | [`CredentialResult`](#credentialresult) | — |

<details>
<summary><code>__construct()</code></summary>

#### `PdoCredentialProvider __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/PdoCredentialProvider.php#L24)

```php
public function __construct(UserStorageInterface $storage, PasswordHasherInterface $hasher)
```

| Param | Type | Description |
|---|---|---|
| `$storage` | [`UserStorageInterface`](#userstorageinterface) | User persistence layer. |
| `$hasher` | [`PasswordHasherInterface`](#passwordhasherinterface) | Password hashing and verification. |

</details>

<details>
<summary><code>verify()</code></summary>

#### `PdoCredentialProvider verify()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Credential/PdoCredentialProvider.php#L36)

```php
public function verify(array $credentials, array $context): CredentialResult
```

| Param | Type | Description |
|---|---|---|
| `$credentials` | `array{email: string, password: string}` | — |
| `$context` | `array<string, mixed>` | Unused by this provider; available for logging or rate-limiting in custom implementations. |

**Returns:** [`CredentialResult`](#credentialresult)

</details>

## `AuthException`

*class* — `AuthKit\Exception\AuthException` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Exception/AuthException.php#L9)

Class AuthException Represents an authentication-related exception.

extends `\Exception`

## `ChallengeExtensionInterface`

*interface* — `AuthKit\Extension\ChallengeExtensionInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/ChallengeExtensionInterface.php#L21)

Extension that both initiates and handles a specific challenge type. When decide() returns LoginDecision::challenge($type), Auth stores a ChallengeRecord and returns a challenge token to the caller. When the user submits their response, Auth::completeChallenge() dispatches to the matching ChallengeExtensionInterface. Only Auth creates the session — never the extension.

extends [`LoginExtensionInterface`](#loginextensioninterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`supportsChallenge()`](#challengeextensioninterface-supportschallenge) | public | `bool` | Returns true when this extension handles the given challenge type. |
| [`completeChallenge()`](#challengeextensioninterface-completechallenge) | public | [`LoginDecision`](#logindecision) | Verify the user's response to a pending challenge. Return LoginDecision::allow() to let Auth create the session. Return LoginDecision::deny(reason) on invalid input — Auth increments the attempt counter. |

<details>
<summary><code>supportsChallenge()</code></summary>

#### `ChallengeExtensionInterface supportsChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/ChallengeExtensionInterface.php#L29)

```php
public function supportsChallenge(string $type): bool
```

Returns true when this extension handles the given challenge type.

| Param | Type | Description |
|---|---|---|
| `$type` | `string` | Challenge type identifier (e.g. 'mfa_totp', 'email_activation'). |

**Returns:** `bool`

</details>

<details>
<summary><code>completeChallenge()</code></summary>

#### `ChallengeExtensionInterface completeChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/ChallengeExtensionInterface.php#L41)

```php
public function completeChallenge(ChallengeRecord $challenge, array $input): LoginDecision
```

Verify the user's response to a pending challenge. Return LoginDecision::allow() to let Auth create the session. Return LoginDecision::deny(reason) on invalid input — Auth increments the attempt counter.

| Param | Type | Description |
|---|---|---|
| `$challenge` | [`ChallengeRecord`](#challengerecord) | The persisted challenge record. |
| `$input` | `array<string, mixed>` | User-supplied verification data (e.g. ['code' => '123456']). |

**Returns:** [`LoginDecision`](#logindecision) — allow() or deny(reason).

</details>

## `LoginExtensionInterface`

*interface* — `AuthKit\Extension\LoginExtensionInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/LoginExtensionInterface.php#L19)

Evaluates whether a login attempt should proceed after credentials are verified. Extensions are registered via Auth::addLoginExtension() and evaluated in registration order. The first deny or challenge decision terminates the pipeline. A session is created only when every registered extension returns allow.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`decide()`](#loginextensioninterface-decide) | public | [`LoginDecision`](#logindecision) | Evaluate the login context and return a decision. |

<details>
<summary><code>decide()</code></summary>

#### `LoginExtensionInterface decide()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/LoginExtensionInterface.php#L27)

```php
public function decide(LoginContext $context): LoginDecision
```

Evaluate the login context and return a decision.

| Param | Type | Description |
|---|---|---|
| `$context` | [`LoginContext`](#logincontext) | Authenticated user with request metadata. |

**Returns:** [`LoginDecision`](#logindecision) — allow(), deny(reason), or challenge(type, payload).

</details>

## `SchemaProviderInterface`

*interface* — `AuthKit\Extension\SchemaProviderInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/SchemaProviderInterface.php#L20)

Declares additional database schema required by an extension or policy. Implement this interface on any class that needs custom tables or columns alongside the core users/sessions tables. PdoUserStorage::createSchema() accepts variadic SchemaProviderInterface instances and executes their SQL after the core tables are created. Every statement in the returned array MUST be idempotent: use CREATE TABLE IF NOT EXISTS, ALTER TABLE … ADD COLUMN IF NOT EXISTS, etc.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`additionalSchema()`](#schemaproviderinterface-additionalschema) | public | `list<string>` | Return SQL statements that set up the additional schema this provider needs. |

<details>
<summary><code>additionalSchema()</code></summary>

#### `SchemaProviderInterface additionalSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Extension/SchemaProviderInterface.php#L28)

```php
public function additionalSchema(string $driver): array
```

Return SQL statements that set up the additional schema this provider needs.

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name: 'mysql', 'sqlite', 'pgsql', etc. |

**Returns:** `list<string>` — Idempotent SQL statements executed in order.

</details>

## `AbstractHook`

*abstract class* — `AuthKit\Hook\AbstractHook` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L8)

implements [`HookInterface`](#hookinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`onRegisterSuccess()`](#abstracthook-onregistersuccess) | public | `void` | — |
| [`onRegisterFailure()`](#abstracthook-onregisterfailure) | public | `void` | — |
| [`onLoginSuccess()`](#abstracthook-onloginsuccess) | public | `void` | — |
| [`onLoginFailure()`](#abstracthook-onloginfailure) | public | `void` | — |
| [`onBeforeRegister()`](#abstracthook-onbeforeregister) | public | `true\|string` | — |
| [`onBeforeLogin()`](#abstracthook-onbeforelogin) | public | `true\|string` | — |
| [`onLogout()`](#abstracthook-onlogout) | public | `void` | — |
| [`onLogoutExpired()`](#abstracthook-onlogoutexpired) | public | `void` | — |
| [`onLogoutForced()`](#abstracthook-onlogoutforced) | public | `void` | — |
| [`onUpdate()`](#abstracthook-onupdate) | public | `void` | — |
| [`onUserActive()`](#abstracthook-onuseractive) | public | `void` | — |
| [`onUserUpdated()`](#abstracthook-onuserupdated) | public | `void` | — |

<details>
<summary><code>onRegisterSuccess()</code></summary>

#### `AbstractHook onRegisterSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L13)

```php
public function onRegisterSuccess(User $user): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onRegisterFailure()</code></summary>

#### `AbstractHook onRegisterFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L18)

```php
public function onRegisterFailure(string $email, AuthException $e): void
```

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$e` | [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>onLoginSuccess()</code></summary>

#### `AbstractHook onLoginSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L23)

```php
public function onLoginSuccess(User $user): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onLoginFailure()</code></summary>

#### `AbstractHook onLoginFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L28)

```php
public function onLoginFailure(string $email, AuthException $e): void
```

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$e` | [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>onBeforeRegister()</code></summary>

#### `AbstractHook onBeforeRegister()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L33)

```php
public function onBeforeRegister(string $email, string $password, array $fields): true|string
```

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$password` | `string` | — |
| `$fields` | `array` | — |

**Returns:** `true\|string`

</details>

<details>
<summary><code>onBeforeLogin()</code></summary>

#### `AbstractHook onBeforeLogin()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L41)

```php
public function onBeforeLogin(User $user): true|string
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

**Returns:** `true\|string`

</details>

<details>
<summary><code>onLogout()</code></summary>

#### `AbstractHook onLogout()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L49)

```php
public function onLogout(User $user): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onLogoutExpired()</code></summary>

#### `AbstractHook onLogoutExpired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L54)

```php
public function onLogoutExpired(): void
```

</details>

<details>
<summary><code>onLogoutForced()</code></summary>

#### `AbstractHook onLogoutForced()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L59)

```php
public function onLogoutForced(int $userId, ?string $reason, int $count): void
```

| Param | Type | Description |
|---|---|---|
| `$userId` | `int` | — |
| `$reason` | `?string` | — |
| `$count` | `int` | — |

</details>

<details>
<summary><code>onUpdate()</code></summary>

#### `AbstractHook onUpdate()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L64)

```php
public function onUpdate(User $user): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onUserActive()</code></summary>

#### `AbstractHook onUserActive()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L69)

```php
public function onUserActive(User $user): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onUserUpdated()</code></summary>

#### `AbstractHook onUserUpdated()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/AbstractHook.php#L74)

```php
public function onUserUpdated(User $user, array $changedFields): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |
| `$changedFields` | `array` | — |

</details>

## `HookInterface`

*interface* — `AuthKit\Hook\HookInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L8)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`onBeforeRegister()`](#hookinterface-onbeforeregister) | public | `true\|string` | Called before registration, can return: - true to allow registration - string with error message to block - false to use default error message |
| [`onRegisterSuccess()`](#hookinterface-onregistersuccess) | public | `void` | Called after successful registration. |
| [`onRegisterFailure()`](#hookinterface-onregisterfailure) | public | `void` | Called when registration fails due to exception or pre-check. |
| [`onBeforeLogin()`](#hookinterface-onbeforelogin) | public | `true\|string` | Called before login. Can block login. |
| [`onLoginSuccess()`](#hookinterface-onloginsuccess) | public | `void` | Called after successful login. |
| [`onLoginFailure()`](#hookinterface-onloginfailure) | public | `void` | Called when login fails (email not found or password mismatch). |
| [`onUserActive()`](#hookinterface-onuseractive) | public | `void` | Called when user is fetched via getUser() and token is valid. |
| [`onLogoutExpired()`](#hookinterface-onlogoutexpired) | public | `void` | Called when session expires due to TTL. |
| [`onLogout()`](#hookinterface-onlogout) | public | `void` | Called when logout() is called explicitly. |
| [`onLogoutForced()`](#hookinterface-onlogoutforced) | public | `void` | Called when sessions are forcibly invalidated by admin or security action. $count = how many sessions were removed. |
| [`onUserUpdated()`](#hookinterface-onuserupdated) | public | `void` | Called after updating user data. $changedFields are the keys that were modified. |

<details>
<summary><code>onBeforeRegister()</code></summary>

#### `HookInterface onBeforeRegister()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L16)

```php
public function onBeforeRegister(string $email, string $password, array $fields): true|string
```

Called before registration, can return: - true to allow registration - string with error message to block - false to use default error message

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$password` | `string` | — |
| `$fields` | `array` | — |

**Returns:** `true\|string`

</details>

<details>
<summary><code>onRegisterSuccess()</code></summary>

#### `HookInterface onRegisterSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L21)

```php
public function onRegisterSuccess(User $user): void
```

Called after successful registration.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onRegisterFailure()</code></summary>

#### `HookInterface onRegisterFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L26)

```php
public function onRegisterFailure(string $email, AuthException $e): void
```

Called when registration fails due to exception or pre-check.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$e` | [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>onBeforeLogin()</code></summary>

#### `HookInterface onBeforeLogin()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L31)

```php
public function onBeforeLogin(User $user): true|string
```

Called before login. Can block login.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

**Returns:** `true\|string`

</details>

<details>
<summary><code>onLoginSuccess()</code></summary>

#### `HookInterface onLoginSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L36)

```php
public function onLoginSuccess(User $user): void
```

Called after successful login.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onLoginFailure()</code></summary>

#### `HookInterface onLoginFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L41)

```php
public function onLoginFailure(string $email, AuthException $e): void
```

Called when login fails (email not found or password mismatch).

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$e` | [`AuthException`](#authexception) | — |

</details>

<details>
<summary><code>onUserActive()</code></summary>

#### `HookInterface onUserActive()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L46)

```php
public function onUserActive(User $user): void
```

Called when user is fetched via getUser() and token is valid.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onLogoutExpired()</code></summary>

#### `HookInterface onLogoutExpired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L51)

```php
public function onLogoutExpired(): void
```

Called when session expires due to TTL.

</details>

<details>
<summary><code>onLogout()</code></summary>

#### `HookInterface onLogout()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L56)

```php
public function onLogout(User $user): void
```

Called when logout() is called explicitly.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |

</details>

<details>
<summary><code>onLogoutForced()</code></summary>

#### `HookInterface onLogoutForced()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L62)

```php
public function onLogoutForced(int $userId, ?string $reason, int $count): void
```

Called when sessions are forcibly invalidated by admin or security action. $count = how many sessions were removed.

| Param | Type | Description |
|---|---|---|
| `$userId` | `int` | — |
| `$reason` | `?string` | — |
| `$count` | `int` | — |

</details>

<details>
<summary><code>onUserUpdated()</code></summary>

#### `HookInterface onUserUpdated()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Hook/HookInterface.php#L68)

```php
public function onUserUpdated(User $user, array $changedFields): void
```

Called after updating user data. $changedFields are the keys that were modified.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |
| `$changedFields` | `array` | — |

</details>

## `Login`

*final class* — `AuthKit\Login` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L33)

Immutable result of an Auth::login() call. Three possible states: - success   : credentials verified, session created, token available. - failure   : authentication rejected, message available. - challenge : credentials verified but an extension requires an additional verification step (MFA, email activation, device check, etc.). No session is created until Auth::completeChallenge() succeeds. Usage (simple flow): $login = $auth->login($email, $password); if ($login->isSuccess()) { header('Location: /dashboard'); } echo $login->message(); Usage (challenge flow, e.g. MFA): $login = $auth->login($email, $password); if ($login->requiresChallenge()) { $_SESSION['authkit_challenge'] = $login->challengeToken(); header('Location: /login/challenge/' . $login->challengeType()); }

**Depends on:** [`User`](#user)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#login-__construct) | private | — | — |
| [`success()`](#login-success) | public static | `self` | Create a successful login result with a session token. |
| [`failure()`](#login-failure) | public static | `self` | Create a failure result. |
| [`challengeRequired()`](#login-challengerequired) | public static | `self` | Create a challenge-required result. Credentials were verified but an extension blocked session creation pending an additional step. No session exists at this point. |
| [`isSuccess()`](#login-issuccess) | public | `bool` | — |
| [`requiresChallenge()`](#login-requireschallenge) | public | `bool` | — |
| [`isFailure()`](#login-isfailure) | public | `bool` | — |
| [`token()`](#login-token) | public | `string\|null` | — |
| [`user()`](#login-user) | public | `User\|null` | — |
| [`challengeToken()`](#login-challengetoken) | public | `string\|null` | — |
| [`challengeType()`](#login-challengetype) | public | `string\|null` | — |
| [`message()`](#login-message) | public | `string` | — |
| [`status()`](#login-status) | public | `string` | — |

<details>
<summary><code>__construct()</code></summary>

#### `Login __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L39)

```php
private function __construct(string $status, string $message, ?string $token, ?User $user, ?string $challengeToken, ?string $challengeType)
```

| Param | Type | Description |
|---|---|---|
| `$status` | `string` | — |
| `$message` | `string` | — |
| `$token` | `?string` | — |
| `$user` | ?[`User`](#user) | — |
| `$challengeToken` | `?string` | — |
| `$challengeType` | `?string` | — |

</details>

<details>
<summary><code>success()</code></summary>

#### `Login success()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L56)

```php
public static function success(string $token, User $user): self
```

Create a successful login result with a session token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | Session token stored in DB and $_SESSION. |
| `$user` | [`User`](#user) | Authenticated user. |

**Returns:** `self`

</details>

<details>
<summary><code>failure()</code></summary>

#### `Login failure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L72)

```php
public static function failure(string $message): self
```

Create a failure result.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | Human-readable reason shown to the user. |

**Returns:** `self`

</details>

<details>
<summary><code>challengeRequired()</code></summary>

#### `Login challengeRequired()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L93)

```php
public static function challengeRequired(string $challengeToken, string $challengeType, User $user, ?string $message): self
```

Create a challenge-required result. Credentials were verified but an extension blocked session creation pending an additional step. No session exists at this point.

| Param | Type | Description |
|---|---|---|
| `$challengeToken` | `string` | Raw token to pass to Auth::completeChallenge(). Store on the caller side (e.g. $_SESSION['authkit_challenge']). |
| `$challengeType` | `string` | Extension-defined type identifier (e.g. 'mfa_totp', 'email_activation'). |
| `$user` | [`User`](#user) | Pre-session user — credentials verified, no session yet. |
| `$message` | `string\|null` | Optional informational message (e.g. 'A code was sent to your email.'). |

**Returns:** `self`

</details>

<details>
<summary><code>isSuccess()</code></summary>

#### `Login isSuccess()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L107)

```php
public function isSuccess(): bool
```

**Returns:** `bool` — True when a session was created and token() is available.

</details>

<details>
<summary><code>requiresChallenge()</code></summary>

#### `Login requiresChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L115)

```php
public function requiresChallenge(): bool
```

**Returns:** `bool` — True when an additional verification step is required.

</details>

<details>
<summary><code>isFailure()</code></summary>

#### `Login isFailure()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L123)

```php
public function isFailure(): bool
```

**Returns:** `bool` — True when authentication was rejected.

</details>

<details>
<summary><code>token()</code></summary>

#### `Login token()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L131)

```php
public function token(): ?string
```

**Returns:** `string\|null` — Session token (set only when isSuccess() is true).

</details>

<details>
<summary><code>user()</code></summary>

#### `Login user()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L139)

```php
public function user(): ?User
```

**Returns:** `User\|null` — Authenticated user (null on failure).

</details>

<details>
<summary><code>challengeToken()</code></summary>

#### `Login challengeToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L147)

```php
public function challengeToken(): ?string
```

**Returns:** `string\|null` — Raw token to pass to completeChallenge() (set only when requiresChallenge()).

</details>

<details>
<summary><code>challengeType()</code></summary>

#### `Login challengeType()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L155)

```php
public function challengeType(): ?string
```

**Returns:** `string\|null` — Challenge type identifier (set only when requiresChallenge()).

</details>

<details>
<summary><code>message()</code></summary>

#### `Login message()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L163)

```php
public function message(): string
```

**Returns:** `string` — Error or informational message (meaningful when isFailure() or requiresChallenge()).

</details>

<details>
<summary><code>status()</code></summary>

#### `Login status()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Login.php#L171)

```php
public function status(): string
```

**Returns:** `string` — Raw status string ('success', 'failure', 'challenge').

</details>

## `LoginContext`

*final class* — `AuthKit\LoginContext` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginContext.php#L15)

Immutable context passed to every LoginExtensionInterface::decide() call. Built after successful credential verification, before session creation. Contains everything an extension needs to make its allow/deny/challenge decision.

**Depends on:** [`User`](#user)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#logincontext-__construct) | public | — | — |
| [`credential()`](#logincontext-credential) | public | `mixed` | Return a single value from credential metadata by key. |
| [`credentials()`](#logincontext-credentials) | public | `array<string, mixed>` | Return all credential metadata from the provider. |

<details>
<summary><code>__construct()</code></summary>

#### `LoginContext __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginContext.php#L23)

```php
public function __construct(User $user, string $ip, string $userAgent, array $credentialMeta)
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | Authenticated user (credentials verified, no session yet). |
| `$ip` | `string` | Client IP address from the request. |
| `$userAgent` | `string` | Client User-Agent header value. |
| `$credentialMeta` | `array<string, mixed>` | Provider-specific metadata from CredentialResult (e.g. provider name, JWT claims). |

</details>

<details>
<summary><code>credential()</code></summary>

#### `LoginContext credential()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginContext.php#L38)

```php
public function credential(string $key, mixed $default): mixed
```

Return a single value from credential metadata by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$default` | `mixed` | Returned when the key is not found. |

**Returns:** `mixed`

</details>

<details>
<summary><code>credentials()</code></summary>

#### `LoginContext credentials()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginContext.php#L48)

```php
public function credentials(): array
```

Return all credential metadata from the provider.

**Returns:** `array<string, mixed>`

</details>

## `LoginDecision`

*final class* — `AuthKit\LoginDecision` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L17)

Immutable decision returned by LoginExtensionInterface::decide(). Three possible states: - allow     : this extension permits the login pipeline to continue. - deny      : this extension blocks login; session will NOT be created. - challenge : an additional verification step is required before session creation.

**Depends on:** `\DateTimeInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#logindecision-__construct) | private | — | — |
| [`allow()`](#logindecision-allow) | public static | `self` | Allow the login pipeline to proceed to the next extension or session creation. |
| [`deny()`](#logindecision-deny) | public static | `self` | Block login with a human-readable reason. |
| [`challenge()`](#logindecision-challenge) | public static | `self` | Require an additional verification step before session creation. |
| [`isAllow()`](#logindecision-isallow) | public | `bool` | — |
| [`isDeny()`](#logindecision-isdeny) | public | `bool` | — |
| [`isChallenge()`](#logindecision-ischallenge) | public | `bool` | — |
| [`reason()`](#logindecision-reason) | public | `string\|null` | — |
| [`challengeType()`](#logindecision-challengetype) | public | `string\|null` | — |
| [`challengePayload()`](#logindecision-challengepayload) | public | `array<string, mixed>` | — |
| [`challengeMessage()`](#logindecision-challengemessage) | public | `string\|null` | — |
| [`challengeExpiresAt()`](#logindecision-challengeexpiresat) | public | `\DateTimeInterface\|null` | — |
| [`challengeMaxAttempts()`](#logindecision-challengemaxattempts) | public | `int\|null` | — |
| [`status()`](#logindecision-status) | public | `string` | — |

<details>
<summary><code>__construct()</code></summary>

#### `LoginDecision __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L23)

```php
private function __construct(string $status, ?string $reason, ?string $challengeType, array $challengePayload, ?string $challengeMessage, ?\DateTimeInterface $challengeExpiresAt, ?int $challengeMaxAttempts)
```

| Param | Type | Description |
|---|---|---|
| `$status` | `string` | — |
| `$reason` | `?string` | — |
| `$challengeType` | `?string` | — |
| `$challengePayload` | `array` | — |
| `$challengeMessage` | `?string` | — |
| `$challengeExpiresAt` | `?\DateTimeInterface` | — |
| `$challengeMaxAttempts` | `?int` | — |

</details>

<details>
<summary><code>allow()</code></summary>

#### `LoginDecision allow()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L39)

```php
public static function allow(): self
```

Allow the login pipeline to proceed to the next extension or session creation.

**Returns:** `self`

</details>

<details>
<summary><code>deny()</code></summary>

#### `LoginDecision deny()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L50)

```php
public static function deny(string $reason): self
```

Block login with a human-readable reason.

| Param | Type | Description |
|---|---|---|
| `$reason` | `string` | Error message returned to the caller (e.g. 'Account suspended.'). |

**Returns:** `self`

</details>

<details>
<summary><code>challenge()</code></summary>

#### `LoginDecision challenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L66)

```php
public static function challenge(string $type, array $payload, ?string $message, ?\DateTimeInterface $expiresAt, ?int $maxAttempts): self
```

Require an additional verification step before session creation.

| Param | Type | Description |
|---|---|---|
| `$type` | `string` | Challenge type handled by a ChallengeExtensionInterface. Examples: 'mfa_totp', 'email_activation', 'new_device'. |
| `$payload` | `array<string, mixed>` | Data stored with the challenge record (e.g. hashed OTP code). |
| `$message` | `string\|null` | Optional message returned to the caller (e.g. 'A code was sent to your email.'). |
| `$expiresAt` | `\DateTimeInterface\|null` | Override default challenge TTL. Defaults to 15 minutes. |
| `$maxAttempts` | `int\|null` | Override default max verification attempts. Defaults to 5. |

**Returns:** `self`

</details>

<details>
<summary><code>isAllow()</code></summary>

#### `LoginDecision isAllow()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L86)

```php
public function isAllow(): bool
```

**Returns:** `bool` — True when this decision allows the pipeline to continue.

</details>

<details>
<summary><code>isDeny()</code></summary>

#### `LoginDecision isDeny()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L94)

```php
public function isDeny(): bool
```

**Returns:** `bool` — True when this decision blocks login.

</details>

<details>
<summary><code>isChallenge()</code></summary>

#### `LoginDecision isChallenge()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L102)

```php
public function isChallenge(): bool
```

**Returns:** `bool` — True when this decision requires a challenge step.

</details>

<details>
<summary><code>reason()</code></summary>

#### `LoginDecision reason()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L110)

```php
public function reason(): ?string
```

**Returns:** `string\|null` — Denial reason (set only when isDeny() is true).

</details>

<details>
<summary><code>challengeType()</code></summary>

#### `LoginDecision challengeType()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L118)

```php
public function challengeType(): ?string
```

**Returns:** `string\|null` — Challenge type identifier (set only when isChallenge() is true).

</details>

<details>
<summary><code>challengePayload()</code></summary>

#### `LoginDecision challengePayload()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L126)

```php
public function challengePayload(): array
```

**Returns:** `array<string, mixed>` — Challenge payload stored in the challenge record.

</details>

<details>
<summary><code>challengeMessage()</code></summary>

#### `LoginDecision challengeMessage()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L134)

```php
public function challengeMessage(): ?string
```

**Returns:** `string\|null` — Optional message for the caller when a challenge is required.

</details>

<details>
<summary><code>challengeExpiresAt()</code></summary>

#### `LoginDecision challengeExpiresAt()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L142)

```php
public function challengeExpiresAt(): ?\DateTimeInterface
```

**Returns:** `\DateTimeInterface\|null` — Custom challenge expiry, or null to use the default (15 minutes).

</details>

<details>
<summary><code>challengeMaxAttempts()</code></summary>

#### `LoginDecision challengeMaxAttempts()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L150)

```php
public function challengeMaxAttempts(): ?int
```

**Returns:** `int\|null` — Custom max attempts, or null to use the default (5).

</details>

<details>
<summary><code>status()</code></summary>

#### `LoginDecision status()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/LoginDecision.php#L158)

```php
public function status(): string
```

**Returns:** `string` — Raw status string ('allow', 'deny', 'challenge').

</details>

## `DefaultMessageProvider`

*class* — `AuthKit\Message\DefaultMessageProvider` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L5)

implements [`MessageProviderInterface`](#messageproviderinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`userAlreadyExists()`](#defaultmessageprovider-useralreadyexists) | public | `string` | — |
| [`passwordHashingFailed()`](#defaultmessageprovider-passwordhashingfailed) | public | `string` | — |
| [`invalidCredentials()`](#defaultmessageprovider-invalidcredentials) | public | `string` | — |
| [`registrationBlocked()`](#defaultmessageprovider-registrationblocked) | public | `string` | — |
| [`userNotFound()`](#defaultmessageprovider-usernotfound) | public | `string` | — |
| [`loginBlocked()`](#defaultmessageprovider-loginblocked) | public | `string` | — |

<details>
<summary><code>userAlreadyExists()</code></summary>

#### `DefaultMessageProvider userAlreadyExists()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L10)

```php
public function userAlreadyExists(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>passwordHashingFailed()</code></summary>

#### `DefaultMessageProvider passwordHashingFailed()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L18)

```php
public function passwordHashingFailed(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>invalidCredentials()</code></summary>

#### `DefaultMessageProvider invalidCredentials()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L26)

```php
public function invalidCredentials(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>registrationBlocked()</code></summary>

#### `DefaultMessageProvider registrationBlocked()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L34)

```php
public function registrationBlocked(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>userNotFound()</code></summary>

#### `DefaultMessageProvider userNotFound()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L42)

```php
public function userNotFound(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>loginBlocked()</code></summary>

#### `DefaultMessageProvider loginBlocked()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/DefaultMessageProvider.php#L50)

```php
public function loginBlocked(): string
```

**Returns:** `string`

</details>

## `MessageProviderInterface`

*interface* — `AuthKit\Message\MessageProviderInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L5)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`userNotFound()`](#messageproviderinterface-usernotfound) | public | `string` | — |
| [`userAlreadyExists()`](#messageproviderinterface-useralreadyexists) | public | `string` | — |
| [`passwordHashingFailed()`](#messageproviderinterface-passwordhashingfailed) | public | `string` | — |
| [`invalidCredentials()`](#messageproviderinterface-invalidcredentials) | public | `string` | — |
| [`registrationBlocked()`](#messageproviderinterface-registrationblocked) | public | `string` | — |
| [`loginBlocked()`](#messageproviderinterface-loginblocked) | public | `string` | — |

<details>
<summary><code>userNotFound()</code></summary>

#### `MessageProviderInterface userNotFound()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L7)

```php
public function userNotFound(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>userAlreadyExists()</code></summary>

#### `MessageProviderInterface userAlreadyExists()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L8)

```php
public function userAlreadyExists(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>passwordHashingFailed()</code></summary>

#### `MessageProviderInterface passwordHashingFailed()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L9)

```php
public function passwordHashingFailed(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>invalidCredentials()</code></summary>

#### `MessageProviderInterface invalidCredentials()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L10)

```php
public function invalidCredentials(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>registrationBlocked()</code></summary>

#### `MessageProviderInterface registrationBlocked()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L11)

```php
public function registrationBlocked(): string
```

**Returns:** `string`

</details>

<details>
<summary><code>loginBlocked()</code></summary>

#### `MessageProviderInterface loginBlocked()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Message/MessageProviderInterface.php#L12)

```php
public function loginBlocked(): string
```

**Returns:** `string`

</details>

## `NativePasswordHasher`

*final class* — `AuthKit\Password\NativePasswordHasher` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/NativePasswordHasher.php#L12)

Password hasher backed by PHP's native password_* functions.

implements [`PasswordHasherInterface`](#passwordhasherinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#nativepasswordhasher-__construct) | public | — | — |
| [`hash()`](#nativepasswordhasher-hash) | public | `string` | — |
| [`verify()`](#nativepasswordhasher-verify) | public | `bool` | — |
| [`needsRehash()`](#nativepasswordhasher-needsrehash) | public | `bool` | — |

<details>
<summary><code>__construct()</code></summary>

#### `NativePasswordHasher __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/NativePasswordHasher.php#L17)

```php
public function __construct(int|string $algorithm)
```

| Param | Type | Description |
|---|---|---|
| `$algorithm` | `int\|string` | Algorithm constant passed to password_hash() (default: PASSWORD_DEFAULT). |

</details>

<details>
<summary><code>hash()</code></summary>

#### `NativePasswordHasher hash()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/NativePasswordHasher.php#L25)

```php
public function hash(string $plain): string
```

| Param | Type | Description |
|---|---|---|
| `$plain` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>verify()</code></summary>

#### `NativePasswordHasher verify()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/NativePasswordHasher.php#L33)

```php
public function verify(string $plain, string $hash): bool
```

| Param | Type | Description |
|---|---|---|
| `$plain` | `string` | — |
| `$hash` | `string` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>needsRehash()</code></summary>

#### `NativePasswordHasher needsRehash()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/NativePasswordHasher.php#L41)

```php
public function needsRehash(string $hash): bool
```

| Param | Type | Description |
|---|---|---|
| `$hash` | `string` | — |

**Returns:** `bool`

</details>

## `PasswordHasherInterface`

*interface* — `AuthKit\Password\PasswordHasherInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/PasswordHasherInterface.php#L12)

Hashes and verifies passwords for storage and authentication.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`hash()`](#passwordhasherinterface-hash) | public | `string` | Hash a plain-text password for storage. |
| [`verify()`](#passwordhasherinterface-verify) | public | `bool` | Verify a plain-text password against a stored hash. |
| [`needsRehash()`](#passwordhasherinterface-needsrehash) | public | `bool` | Check whether an existing hash should be upgraded. |

<details>
<summary><code>hash()</code></summary>

#### `PasswordHasherInterface hash()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/PasswordHasherInterface.php#L20)

```php
public function hash(string $plain): string
```

Hash a plain-text password for storage.

| Param | Type | Description |
|---|---|---|
| `$plain` | `string` | Plain-text password. |

**Returns:** `string` — Hashed password.

</details>

<details>
<summary><code>verify()</code></summary>

#### `PasswordHasherInterface verify()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/PasswordHasherInterface.php#L29)

```php
public function verify(string $plain, string $hash): bool
```

Verify a plain-text password against a stored hash.

| Param | Type | Description |
|---|---|---|
| `$plain` | `string` | Plain-text password. |
| `$hash` | `string` | Stored hash to verify against. |

**Returns:** `bool` — True when the password matches the hash.

</details>

<details>
<summary><code>needsRehash()</code></summary>

#### `PasswordHasherInterface needsRehash()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Password/PasswordHasherInterface.php#L37)

```php
public function needsRehash(string $hash): bool
```

Check whether an existing hash should be upgraded.

| Param | Type | Description |
|---|---|---|
| `$hash` | `string` | Stored hash to inspect. |

**Returns:** `bool` — True when the hash should be re-hashed on next login.

</details>

## `PdoUserStorage`

*final class* — `AuthKit\Storage\PdoUserStorage` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L21)

PDO-backed user storage supporting MySQL/MariaDB and SQLite. Implements SchemaMigrationInterface so that Auth::createSchema() can bootstrap the required tables and delegate to registered extension providers.

implements [`UserStorageInterface`](#userstorageinterface), [`SchemaMigrationInterface`](#schemamigrationinterface)

**Depends on:** `PDO`, [`UserIdPolicyInterface`](#useridpolicyinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#pdouserstorage-__construct) | public | — | — |
| [`findByEmail()`](#pdouserstorage-findbyemail) | public | ?[`User`](#user) | — |
| [`findByToken()`](#pdouserstorage-findbytoken) | public | ?[`User`](#user) | — |
| [`findById()`](#pdouserstorage-findbyid) | public | ?[`User`](#user) | — |
| [`createUser()`](#pdouserstorage-createuser) | public | [`User`](#user) | — |
| [`updateUser()`](#pdouserstorage-updateuser) | public | [`User`](#user) | — |
| [`storeToken()`](#pdouserstorage-storetoken) | public | `void` | — |
| [`deleteToken()`](#pdouserstorage-deletetoken) | public | `int` | — |
| [`deleteTokensByUserId()`](#pdouserstorage-deletetokensbyuserid) | public | `int` | — |
| [`purgeExpiredTokens()`](#pdouserstorage-purgeexpiredtokens) | public | `void` | Delete all expired session tokens. Typically called from a maintenance job or cron — not part of the login flow. The caller is responsible for supplying the reference time to ensure timezone-consistent comparisons against the stored expires_at column. |
| [`createSchema()`](#pdouserstorage-createschema) | public | `void` | Create the core users/sessions tables and run any additional schema declared by the given providers. Column types for the primary key and foreign key columns are determined by the injected UserIdPolicyInterface so that the schema matches the chosen ID strategy (e.g. INT AUTO_INCREMENT for NullUserIdPolicy, CHAR(36) for UUID). The method is idempotent — safe to call multiple times. |

<details>
<summary><code>__construct()</code></summary>

#### `PdoUserStorage __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L27)

```php
public function __construct(PDO $pdo, UserIdPolicyInterface $userIdPolicy)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Database connection. |
| `$userIdPolicy` | [`UserIdPolicyInterface`](#useridpolicyinterface) | ID generation policy (default: database auto-increment). |

</details>

<details>
<summary><code>findByEmail()</code></summary>

#### `PdoUserStorage findByEmail()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L36)

```php
public function findByEmail(string $email): ?User
```

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |

**Returns:** ?[`User`](#user)

</details>

<details>
<summary><code>findByToken()</code></summary>

#### `PdoUserStorage findByToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L48)

```php
public function findByToken(string $token, \DateTimeImmutable $now): ?User
```

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |
| `$now` | `\DateTimeImmutable` | — |

**Returns:** ?[`User`](#user)

</details>

<details>
<summary><code>findById()</code></summary>

#### `PdoUserStorage findById()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L69)

```php
public function findById(int|string $id): ?User
```

| Param | Type | Description |
|---|---|---|
| `$id` | `int\|string` | — |

**Returns:** ?[`User`](#user)

</details>

<details>
<summary><code>createUser()</code></summary>

#### `PdoUserStorage createUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L81)

```php
public function createUser(string $email, string $passwordHash, array $fields): User
```

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | — |
| `$passwordHash` | `string` | — |
| `$fields` | `array` | — |

**Returns:** [`User`](#user)

</details>

<details>
<summary><code>updateUser()</code></summary>

#### `PdoUserStorage updateUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L105)

```php
public function updateUser(User $user, array $fields): User
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |
| `$fields` | `array` | — |

**Returns:** [`User`](#user)

</details>

<details>
<summary><code>storeToken()</code></summary>

#### `PdoUserStorage storeToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L132)

```php
public function storeToken(User $user, string $token, ?\DateTimeInterface $expiresAt): void
```

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | — |
| `$token` | `string` | — |
| `$expiresAt` | `?\DateTimeInterface` | — |

</details>

<details>
<summary><code>deleteToken()</code></summary>

#### `PdoUserStorage deleteToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L147)

```php
public function deleteToken(string $token): int
```

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `int`

</details>

<details>
<summary><code>deleteTokensByUserId()</code></summary>

#### `PdoUserStorage deleteTokensByUserId()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L158)

```php
public function deleteTokensByUserId(int|string $userId): int
```

| Param | Type | Description |
|---|---|---|
| `$userId` | `int\|string` | — |

**Returns:** `int`

</details>

<details>
<summary><code>purgeExpiredTokens()</code></summary>

#### `PdoUserStorage purgeExpiredTokens()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L176)

```php
public function purgeExpiredTokens(\DateTimeImmutable $now): void
```

Delete all expired session tokens. Typically called from a maintenance job or cron — not part of the login flow. The caller is responsible for supplying the reference time to ensure timezone-consistent comparisons against the stored expires_at column.

| Param | Type | Description |
|---|---|---|
| `$now` | `\DateTimeImmutable` | Sessions with expires_at before this value will be deleted. |

</details>

<details>
<summary><code>createSchema()</code></summary>

#### `PdoUserStorage createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/PdoUserStorage.php#L197)

```php
public function createSchema(SchemaProviderInterface $providers): void
```

Create the core users/sessions tables and run any additional schema declared by the given providers. Column types for the primary key and foreign key columns are determined by the injected UserIdPolicyInterface so that the schema matches the chosen ID strategy (e.g. INT AUTO_INCREMENT for NullUserIdPolicy, CHAR(36) for UUID). The method is idempotent — safe to call multiple times.

| Param | Type | Description |
|---|---|---|
| `$providers` | [`SchemaProviderInterface`](#schemaproviderinterface) | — |

</details>

## `SchemaMigrationInterface`

*interface* — `AuthKit\Storage\SchemaMigrationInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/SchemaMigrationInterface.php#L24)

Marks a storage backend that is capable of creating its own database schema. Implement this interface alongside UserStorageInterface when the storage layer can bootstrap its tables on demand (e.g. PdoUserStorage). Storage implementations that keep data entirely in memory or delegate persistence to an external service do not need to implement this. The Auth facade calls createSchema() when Auth::createSchema() is invoked, passing all registered extensions that implement SchemaProviderInterface so that each extension's required tables and columns are created in the same transaction context.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`createSchema()`](#schemamigrationinterface-createschema) | public | `void` | Create the core schema plus any additional schema declared by the given providers. The method must be idempotent — safe to call multiple times against an already-initialised database (e.g. use CREATE TABLE IF NOT EXISTS). |

<details>
<summary><code>createSchema()</code></summary>

#### `SchemaMigrationInterface createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/SchemaMigrationInterface.php#L35)

```php
public function createSchema(SchemaProviderInterface $providers): void
```

Create the core schema plus any additional schema declared by the given providers. The method must be idempotent — safe to call multiple times against an already-initialised database (e.g. use CREATE TABLE IF NOT EXISTS).

| Param | Type | Description |
|---|---|---|
| `$providers` | [`SchemaProviderInterface`](#schemaproviderinterface) | — |

</details>

## `UserStorageInterface`

*interface* — `AuthKit\Storage\UserStorageInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L14)

Persists and retrieves user records and session tokens.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`findByEmail()`](#userstorageinterface-findbyemail) | public | `User\|null` | Find a user by their email address. |
| [`findByToken()`](#userstorageinterface-findbytoken) | public | `User\|null` | Find a user by their session token. $now is used to filter out expired sessions at the query level. Pass the current time from your clock to ensure timezone-consistent expiry comparisons against the stored expires_at column. |
| [`findById()`](#userstorageinterface-findbyid) | public | `User\|null` | Find a user by their ID. |
| [`createUser()`](#userstorageinterface-createuser) | public | [`User`](#user) | Create and persist a new user. |
| [`updateUser()`](#userstorageinterface-updateuser) | public | [`User`](#user) | Update fields on an existing user. |
| [`storeToken()`](#userstorageinterface-storetoken) | public | `void` | Store a session token for a user. |
| [`deleteToken()`](#userstorageinterface-deletetoken) | public | `int` | Delete a single session token. |
| [`deleteTokensByUserId()`](#userstorageinterface-deletetokensbyuserid) | public | `int` | Delete all session tokens for a given user. |
| [`createSchema()`](#userstorageinterface-createschema) | public | `void` | Create the necessary database schema for user storage. |

<details>
<summary><code>findByEmail()</code></summary>

#### `UserStorageInterface findByEmail()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L22)

```php
public function findByEmail(string $email): ?User
```

Find a user by their email address.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | The email address to search for. |

**Returns:** `User\|null`

</details>

<details>
<summary><code>findByToken()</code></summary>

#### `UserStorageInterface findByToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L35)

```php
public function findByToken(string $token, \DateTimeImmutable $now): ?User
```

Find a user by their session token. $now is used to filter out expired sessions at the query level. Pass the current time from your clock to ensure timezone-consistent expiry comparisons against the stored expires_at column.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | The session token to search for. |
| `$now` | `\DateTimeImmutable` | Current time used to filter expired sessions. |

**Returns:** `User\|null`

</details>

<details>
<summary><code>findById()</code></summary>

#### `UserStorageInterface findById()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L43)

```php
public function findById(int|string $id): ?User
```

Find a user by their ID.

| Param | Type | Description |
|---|---|---|
| `$id` | `int\|string` | The user ID to search for. |

**Returns:** `User\|null`

</details>

<details>
<summary><code>createUser()</code></summary>

#### `UserStorageInterface createUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L53)

```php
public function createUser(string $email, string $passwordHash, array $fields): User
```

Create and persist a new user.

| Param | Type | Description |
|---|---|---|
| `$email` | `string` | User's email address. |
| `$passwordHash` | `string` | Hashed password. |
| `$fields` | `array<string, mixed>` | Additional fields to store. |

**Returns:** [`User`](#user)

</details>

<details>
<summary><code>updateUser()</code></summary>

#### `UserStorageInterface updateUser()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L62)

```php
public function updateUser(User $user, array $fields): User
```

Update fields on an existing user.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | The user to update. |
| `$fields` | `array<string, mixed>` | Associative array of fields to update. |

**Returns:** [`User`](#user) — Updated user.

</details>

<details>
<summary><code>storeToken()</code></summary>

#### `UserStorageInterface storeToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L72)

```php
public function storeToken(User $user, string $token, ?\DateTimeInterface $expiresAt): void
```

Store a session token for a user.

| Param | Type | Description |
|---|---|---|
| `$user` | [`User`](#user) | The user to associate the token with. |
| `$token` | `string` | The session token to store. |
| `$expiresAt` | `\DateTimeInterface\|null` | Optional expiration date/time. |

</details>

<details>
<summary><code>deleteToken()</code></summary>

#### `UserStorageInterface deleteToken()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L80)

```php
public function deleteToken(string $token): int
```

Delete a single session token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | The session token to delete. |

**Returns:** `int` — Number of tokens deleted (0 or 1).

</details>

<details>
<summary><code>deleteTokensByUserId()</code></summary>

#### `UserStorageInterface deleteTokensByUserId()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L88)

```php
public function deleteTokensByUserId(int|string $userId): int
```

Delete all session tokens for a given user.

| Param | Type | Description |
|---|---|---|
| `$userId` | `int\|string` | The user ID whose tokens should be removed. |

**Returns:** `int` — Number of tokens deleted.

</details>

<details>
<summary><code>createSchema()</code></summary>

#### `UserStorageInterface createSchema()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Storage/UserStorageInterface.php#L95)

```php
public function createSchema(): void
```

Create the necessary database schema for user storage.

</details>

## `BearerHeaderTransport`

*final class* — `AuthKit\Transport\BearerHeaderTransport` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/BearerHeaderTransport.php#L16)

Token transport for stateless API clients using HTTP Bearer authentication. Reads the token from the Authorization: Bearer <token> request header. set() and clear() are no-ops — the client is responsible for storing and discarding the token on its own side.

implements [`TokenTransportInterface`](#tokentransportinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`initialize()`](#bearerheadertransport-initialize) | public | `void` | — |
| [`get()`](#bearerheadertransport-get) | public | `?string` | — |
| [`set()`](#bearerheadertransport-set) | public | `void` | — |
| [`clear()`](#bearerheadertransport-clear) | public | `void` | — |

<details>
<summary><code>initialize()</code></summary>

#### `BearerHeaderTransport initialize()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/BearerHeaderTransport.php#L21)

```php
public function initialize(): void
```

</details>

<details>
<summary><code>get()</code></summary>

#### `BearerHeaderTransport get()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/BearerHeaderTransport.php#L30)

```php
public function get(): ?string
```

**Returns:** `?string`

</details>

<details>
<summary><code>set()</code></summary>

#### `BearerHeaderTransport set()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/BearerHeaderTransport.php#L48)

```php
public function set(string $token): void
```

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

</details>

<details>
<summary><code>clear()</code></summary>

#### `BearerHeaderTransport clear()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/BearerHeaderTransport.php#L57)

```php
public function clear(): void
```

</details>

## `PhpSessionTransport`

*final class* — `AuthKit\Transport\PhpSessionTransport` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L15)

Token transport backed by the native PHP session. This is the default transport for web applications. The token is stored under a configurable key in $_SESSION and travels via session cookie.

implements [`TokenTransportInterface`](#tokentransportinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#phpsessiontransport-__construct) | public | — | — |
| [`initialize()`](#phpsessiontransport-initialize) | public | `void` | — |
| [`get()`](#phpsessiontransport-get) | public | `?string` | — |
| [`set()`](#phpsessiontransport-set) | public | `void` | — |
| [`clear()`](#phpsessiontransport-clear) | public | `void` | — |

<details>
<summary><code>__construct()</code></summary>

#### `PhpSessionTransport __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L20)

```php
public function __construct(string $key)
```

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | $_SESSION key used to store the token (default: 'auth_token'). |

</details>

<details>
<summary><code>initialize()</code></summary>

#### `PhpSessionTransport initialize()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L27)

```php
public function initialize(): void
```

</details>

<details>
<summary><code>get()</code></summary>

#### `PhpSessionTransport get()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L37)

```php
public function get(): ?string
```

**Returns:** `?string`

</details>

<details>
<summary><code>set()</code></summary>

#### `PhpSessionTransport set()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L47)

```php
public function set(string $token): void
```

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

</details>

<details>
<summary><code>clear()</code></summary>

#### `PhpSessionTransport clear()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/PhpSessionTransport.php#L55)

```php
public function clear(): void
```

</details>

## `TokenTransportInterface`

*interface* — `AuthKit\Transport\TokenTransportInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/TokenTransportInterface.php#L15)

Abstracts how the active session token is stored and retrieved on the client side. Implement this interface to swap the default PHP session transport for any other mechanism (Bearer header, cookie, in-memory, etc.).

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`initialize()`](#tokentransportinterface-initialize) | public | `void` | Called once during Auth initialization. Use this to start a session, open storage, or perform any setup required before the transport can be used. |
| [`get()`](#tokentransportinterface-get) | public | `string\|null` | Return the current token, or null if none is present. |
| [`set()`](#tokentransportinterface-set) | public | `void` | Store the token after a successful login. |
| [`clear()`](#tokentransportinterface-clear) | public | `void` | Remove the stored token on logout. |

<details>
<summary><code>initialize()</code></summary>

#### `TokenTransportInterface initialize()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/TokenTransportInterface.php#L25)

```php
public function initialize(): void
```

Called once during Auth initialization. Use this to start a session, open storage, or perform any setup required before the transport can be used.

</details>

<details>
<summary><code>get()</code></summary>

#### `TokenTransportInterface get()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/TokenTransportInterface.php#L32)

```php
public function get(): ?string
```

Return the current token, or null if none is present.

**Returns:** `string\|null`

</details>

<details>
<summary><code>set()</code></summary>

#### `TokenTransportInterface set()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/TokenTransportInterface.php#L40)

```php
public function set(string $token): void
```

Store the token after a successful login.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

</details>

<details>
<summary><code>clear()</code></summary>

#### `TokenTransportInterface clear()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/Transport/TokenTransportInterface.php#L47)

```php
public function clear(): void
```

Remove the stored token on logout.

</details>

## `User`

*class* — `AuthKit\User` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L10)

Class User Represents a user in the authentication system. Provides methods to access user attributes and serialize to JSON.

implements `\JsonSerializable`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$attributes` | `array<string, mixed>` | private | — |
| `$extraHiddenKeys` | `string[]` | private static | Extra keys provided by the application to hide in addition to defaults. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#user-__construct) | public | — | User constructor. Initializes the user with an array of attributes. |
| [`setHiddenKeys()`](#user-sethiddenkeys) | public static | `void` | Configure additional keys to hide (replaces previously set extra keys). Usage: User::setHiddenKeys(['api_key', 'private_notes']); |
| [`addHiddenKeys()`](#user-addhiddenkeys) | public static | `void` | Add more keys to hide (keeps any previously configured extras). |
| [`getHiddenKeys()`](#user-gethiddenkeys) | public static | `string[]` | Returns the complete effective hidden-keys list (defaults + extras). |
| [`filteredAttributes()`](#user-filteredattributes) | private | `array<string, mixed>` | Return a copy of attributes with hidden keys removed. |
| [`get()`](#user-get) | public | `mixed` | Returns one field or multiple fields by key(s). Example: get('email') or get(['email', 'name']) |
| [`getAll()`](#user-getall) | public | `array<string, mixed>` | Returns all available user data EXCEPT hidden keys. |
| [`getId()`](#user-getid) | public | `int\|string\|null` | Returns the user's ID, or null if not yet persisted. |
| [`getEmail()`](#user-getemail) | public | `?string` | Get the user's email address. Returns null if the email is not set. |
| [`jsonSerialize()`](#user-jsonserialize) | public | `array<string, mixed>` | Support for json_encode($user) Returns attributes without hidden keys. |
| [`toJson()`](#user-tojson) | public | `string` | Convert to JSON string manually (without hidden keys). |
| [`_getAllRaw()`](#user-_getallraw) | public | `array<string, mixed>` | Internal-only raw accessor to full attributes including hidden keys. Prefer NOT to use this in application code. Keep it private/protected if undesired. |

<details>
<summary><code>__construct()</code></summary>

#### `User __construct()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L39)

```php
public function __construct(array $attributes)
```

User constructor. Initializes the user with an array of attributes.

| Param | Type | Description |
|---|---|---|
| `$attributes` | `array<string, mixed>` | Associative array of user attributes. |

</details>

<details>
<summary><code>setHiddenKeys()</code></summary>

#### `User setHiddenKeys()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L50)

```php
public static function setHiddenKeys(array $keys): void
```

Configure additional keys to hide (replaces previously set extra keys). Usage: User::setHiddenKeys(['api_key', 'private_notes']);

| Param | Type | Description |
|---|---|---|
| `$keys` | `string[]` | — |

</details>

<details>
<summary><code>addHiddenKeys()</code></summary>

#### `User addHiddenKeys()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L61)

```php
public static function addHiddenKeys(array $keys): void
```

Add more keys to hide (keeps any previously configured extras).

| Param | Type | Description |
|---|---|---|
| `$keys` | `string[]` | — |

</details>

<details>
<summary><code>getHiddenKeys()</code></summary>

#### `User getHiddenKeys()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L74)

```php
public static function getHiddenKeys(): array
```

Returns the complete effective hidden-keys list (defaults + extras).

**Returns:** `string[]`

</details>

<details>
<summary><code>filteredAttributes()</code></summary>

#### `User filteredAttributes()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L87)

```php
private function filteredAttributes(): array
```

Return a copy of attributes with hidden keys removed.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>get()</code></summary>

#### `User get()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L105)

```php
public function get(array|string $fields): mixed
```

Returns one field or multiple fields by key(s). Example: get('email') or get(['email', 'name'])

| Param | Type | Description |
|---|---|---|
| `$fields` | `array<string>\|string` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>getAll()</code></summary>

#### `User getAll()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L123)

```php
public function getAll(): array
```

Returns all available user data EXCEPT hidden keys.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>getId()</code></summary>

#### `User getId()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L133)

```php
public function getId(): int|string|null
```

Returns the user's ID, or null if not yet persisted.

**Returns:** `int\|string\|null`

</details>

<details>
<summary><code>getEmail()</code></summary>

#### `User getEmail()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L143)

```php
public function getEmail(): ?string
```

Get the user's email address. Returns null if the email is not set.

**Returns:** `?string`

</details>

<details>
<summary><code>jsonSerialize()</code></summary>

#### `User jsonSerialize()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L155)

```php
public function jsonSerialize(): array
```

Support for json_encode($user) Returns attributes without hidden keys.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>toJson()</code></summary>

#### `User toJson()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L164)

```php
public function toJson(bool $pretty): string
```

Convert to JSON string manually (without hidden keys).

| Param | Type | Description |
|---|---|---|
| `$pretty` | `bool` | Whether to pretty-print the JSON. |

**Returns:** `string`

</details>

<details>
<summary><code>_getAllRaw()</code></summary>

#### `User _getAllRaw()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/User.php#L178)

```php
public function _getAllRaw(): array
```

Internal-only raw accessor to full attributes including hidden keys. Prefer NOT to use this in application code. Keep it private/protected if undesired.

**Returns:** `array<string, mixed>`

</details>

## `NullUserIdPolicy`

*final class* — `AuthKit\UserId\NullUserIdPolicy` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/NullUserIdPolicy.php#L12)

Delegates user ID assignment to the database (auto-increment).

implements [`UserIdPolicyInterface`](#useridpolicyinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`generate()`](#nulluseridpolicy-generate) | public | `int\|string\|null` | — |
| [`idColumnDefinition()`](#nulluseridpolicy-idcolumndefinition) | public | `string` | — |
| [`userIdForeignType()`](#nulluseridpolicy-useridforeigntype) | public | `string` | — |

<details>
<summary><code>generate()</code></summary>

#### `NullUserIdPolicy generate()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/NullUserIdPolicy.php#L17)

```php
public function generate(): int|string|null
```

**Returns:** `int\|string\|null`

</details>

<details>
<summary><code>idColumnDefinition()</code></summary>

#### `NullUserIdPolicy idColumnDefinition()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/NullUserIdPolicy.php#L25)

```php
public function idColumnDefinition(string $driver): string
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>userIdForeignType()</code></summary>

#### `NullUserIdPolicy userIdForeignType()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/NullUserIdPolicy.php#L36)

```php
public function userIdForeignType(string $driver): string
```

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | — |

**Returns:** `string`

</details>

## `UserIdPolicyInterface`

*interface* — `AuthKit\UserId\UserIdPolicyInterface` · [source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/UserIdPolicyInterface.php#L19)

Determines how a new user ID is assigned before insertion and what column type that choice requires in the database schema. Return null from generate() to let the database produce the ID (auto-increment). Return a value to supply the ID from the application layer (e.g. UUID v4). The two schema methods allow PdoUserStorage::createSchema() to emit the correct column definitions without hardcoding a specific strategy.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`generate()`](#useridpolicyinterface-generate) | public | `int\|string\|null` | Generate an ID for a new user, or return null to delegate to the database. |
| [`idColumnDefinition()`](#useridpolicyinterface-idcolumndefinition) | public | `string` | Full inline SQL column definition for the users.id primary key column. When the returned string contains the token "PRIMARY KEY", createSchema() omits the separate PRIMARY KEY table constraint (required for SQLite AUTOINCREMENT which must be declared inline). Examples: NullUserIdPolicy / sqlite → "INTEGER PRIMARY KEY AUTOINCREMENT" NullUserIdPolicy / mysql → "INT NOT NULL AUTO_INCREMENT" UuidUserIdPolicy / any → "CHAR(36) NOT NULL" |
| [`userIdForeignType()`](#useridpolicyinterface-useridforeigntype) | public | `string` | SQL type for foreign key columns that reference users.id. Used by createSchema() for the sessions.user_id column and should be compatible with the type chosen by idColumnDefinition(). Examples: NullUserIdPolicy / sqlite → "INTEGER NOT NULL" NullUserIdPolicy / mysql → "INT NOT NULL" UuidUserIdPolicy / any → "CHAR(36) NOT NULL" |

<details>
<summary><code>generate()</code></summary>

#### `UserIdPolicyInterface generate()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/UserIdPolicyInterface.php#L26)

```php
public function generate(): int|string|null
```

Generate an ID for a new user, or return null to delegate to the database.

**Returns:** `int\|string\|null` — Application-generated ID, or null for database auto-increment.

</details>

<details>
<summary><code>idColumnDefinition()</code></summary>

#### `UserIdPolicyInterface idColumnDefinition()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/UserIdPolicyInterface.php#L43)

```php
public function idColumnDefinition(string $driver): string
```

Full inline SQL column definition for the users.id primary key column. When the returned string contains the token "PRIMARY KEY", createSchema() omits the separate PRIMARY KEY table constraint (required for SQLite AUTOINCREMENT which must be declared inline). Examples: NullUserIdPolicy / sqlite  → "INTEGER PRIMARY KEY AUTOINCREMENT" NullUserIdPolicy / mysql   → "INT NOT NULL AUTO_INCREMENT" UuidUserIdPolicy / any     → "CHAR(36) NOT NULL"

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name ('mysql', 'sqlite', …). |

**Returns:** `string` — SQL fragment placed immediately after the column name.

</details>

<details>
<summary><code>userIdForeignType()</code></summary>

#### `UserIdPolicyInterface userIdForeignType()`

[source](https://github.com/rafalmasiarek/php-AuthKit/blob/main/src/UserId/UserIdPolicyInterface.php#L59)

```php
public function userIdForeignType(string $driver): string
```

SQL type for foreign key columns that reference users.id. Used by createSchema() for the sessions.user_id column and should be compatible with the type chosen by idColumnDefinition(). Examples: NullUserIdPolicy / sqlite  → "INTEGER NOT NULL" NullUserIdPolicy / mysql   → "INT NOT NULL" UuidUserIdPolicy / any     → "CHAR(36) NOT NULL"

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name. |

**Returns:** `string` — SQL type fragment (no column name).

</details>




<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-AuthKit
