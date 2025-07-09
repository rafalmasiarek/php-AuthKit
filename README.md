# AuthKit

AuthKit is a lightweight and extensible PHP authentication library supporting:

- User registration and login with hashed passwords
- Secure session-based token authentication (with optional TTL)
- Hook system for extending behavior (audit logs, IP bans, custom rules)
- Support for custom user fields (e.g. `active`, `name`, etc.)
- Pluggable storage backend (PDO included)

---

## 🚀 Installation

```bash
composer require yourvendor/authkit
```

Or clone and load via autoloader if not using Composer.

---

## 📦 Structure

- `AuthKit\Auth` – Main class for authentication operations
- `AuthKit\User` – Represents an authenticated user
- `AuthKit\Storage\UserStorageInterface` – Pluggable storage contract
- `AuthKit\Storage\PdoUserStorage` – Default PDO storage
- `AuthKit\Hook\HookInterface` – Optional hook callbacks
- `AuthKit\Message\MessageProviderInterface` – Customizable messages

---

## 🔧 Configuration

```php
use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;
use App\MyHook;

$auth = new Auth(
    new PdoUserStorage($pdo),
    new MyHook(),       // Optional: implements HookInterface
    3600                // Token TTL in seconds (0 = no expiry)
);
```

---

## 🧠 API Reference

### `register(string $email, string $password, array $customFields = [], array $additionalChecks = []): User|string`

Registers a new user.

- Returns `User` object on success
- Returns `string` error message on failure (or throws `AuthException` if `throwExceptions` is `true`)
- `customFields`: Any user-defined fields (e.g. `name`, `active`)
- `additionalChecks`: Custom validations as `callable($email, $password, $fields): true|string`

### `login(string $email, string $password, array $additionalChecks = []): string|null`

Logs in the user and returns a session token.

- Validates password and optionally runs custom `callable(User): true|string` checks
- Returns `string` token or `null`

### `logout(): void`

Logs the user out by removing the token from session and storage.

### `getUser(): ?User`

Returns the currently authenticated user or `null`. Automatically checks token expiration and triggers hook if session expired.

### `isLoggedIn(): bool`

Shortcut for checking if `getUser()` returns a valid user.

### `updateUser(User $user, array $updates): User`

Updates fields for a given user and triggers `onUserUpdated()` hook.

---

## 🪝 Hook Interface (Optional)

You can implement `HookInterface` to extend functionality:

```php
interface HookInterface {
    public function onBeforeRegister(string $email, string $password, array $fields): true|string;
    public function onRegisterSuccess(User $user): void;
    public function onRegisterFailure(string $email, Throwable $e): void;
    public function onLoginSuccess(User $user): void;
    public function onLoginFailure(string $email, AuthException $e): void;
    public function onBeforeLogin(User $user): true|string;
    public function onLogout(User $user): void;
    public function onLogoutExpired(): void;
    public function onUserUpdated(User $user, array $changedFields): void;
}
```

---

## 🧪 Example Usage

```php
use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;

$pdo = new PDO('sqlite:/tmp/mydb.sqlite');
$auth = new Auth(new PdoUserStorage($pdo));

$result = $auth->register("foo@example.com", "Password123!", ['name' => 'Foo']);
if ($result instanceof \AuthKit\User) {
    echo "Registered!";
} else {
    echo "Error: $result";
}
```

---

## 🔐 Security Features

- Password hashing with `password_hash()`
- Session token with optional expiration (TTL)
- Hook-based IP/fingerprint/session verification

---

## 💡 Advanced Hook Example – Brute Force & Password Strength

```php
use AuthKit\User;
use AuthKit\Hook\AbstractHook;
use AuthKit\Exception\AuthException;
use PDO;

class MyHook extends AbstractHook
{
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function onBeforeRegister(string $email, string $password, array $fields): true|string
    {
        if (strlen($password) < 12) return "Password must be at least 12 characters.";
        if (!preg_match('/[A-Z]/', $password)) return "Password must include an uppercase letter.";
        if (!preg_match('/\d/', $password)) return "Password must include at least one number.";
        if (!preg_match('/[\W_]/', $password)) return "Password must include a special character.";
        return true;
    }

    public function onBeforeLogin(User $user): true|string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND created_at > datetime('now', '-10 minutes')");
        $stmt->execute(['ip' => $ip]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= 5) {
            return "Too many failed login attempts. Please wait 10 minutes.";
        }

        return true;
    }

    public function onLoginFailure(string $email, AuthException $e): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $this->pdo->prepare("INSERT INTO login_attempts (ip, email, created_at) VALUES (:ip, :email, :ts)");
        $stmt->execute([
            'ip' => $ip,
            'email' => $email,
            'ts' => date('Y-m-d H:i:s')
        ]);
    }

    public function onLoginSuccess(User $user): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->pdo->prepare("DELETE FROM login_attempts WHERE ip = :ip")->execute(['ip' => $ip]);
    }
}
```

---

## 📂 Directory Structure

```
src/
├── Auth.php
├── User.php
├── Hook/
│   ├── HookInterface.php
│   └── AbstractHook.php
├── Message/
│   ├── MessageProviderInterface.php
│   └── DefaultMessageProvider.php
├── Storage/
│   ├── PdoUserStorage.php
│   └── UserStorageInterface.php
└── Exception/
    └── AuthException.php
```

---

## 🛠 Custom Storage Layer

To use your own storage system, implement `UserStorageInterface`:

```php
interface UserStorageInterface {
    public function findByEmail(string $email): ?User;
    public function findByToken(string $token): ?User;
    public function createUser(string $email, string $passwordHash, array $fields = []): User;
    public function storeToken(User $user, string $token, ?DateTime $expiresAt): void;
    public function updateUser(User $user, array $updates): User;
    public function deleteToken(string $token): void;
}
```

---

## 🧑‍💻 License

MIT License.