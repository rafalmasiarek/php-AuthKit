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
$storage->createSchema(); // creates users + sessions tables

$auth = new Auth($storage);

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
)
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

`LoginContext` carries `$user`, `$ip`, `$userAgent`, and optional `$meta`.

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

## License

MIT
