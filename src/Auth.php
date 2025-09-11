<?php

namespace AuthKit;

use AuthKit\Storage\UserStorageInterface;
use AuthKit\Exception\AuthException;
use AuthKit\Hook\HookInterface;
use AuthKit\Message\MessageProviderInterface;
use AuthKit\Message\DefaultMessageProvider;
use Ramsey\Uuid\Uuid;
use DateInterval;
use DateTime;

class Auth
{
  /**
   * @var UserStorageInterface
   */
  // This interface is used to interact with the user storage.
  // It provides methods to find users by email or token, create new users, update existing users,
  // store session tokens, and delete tokens.
  // The Auth class uses this interface to perform user authentication and management operations.
  // The UserStorageInterface is implemented by various storage classes, such as PdoUserStorage
  // or InMemoryUserStorage, which provide different storage backends (e.g., database, file system,
  // in-memory).
  // The Auth class does not depend on any specific storage implementation, allowing for flexibility
  // in choosing the storage backend.
  // The UserStorageInterface is designed to be simple and focused on user-related operations.
  // It does not include methods for session management or other non-user-related operations.
  // The Auth class handles session management separately, using the session storage provided by PHP.
  // This allows the Auth class to be decoupled from the session management logic, making it easier
  // to test and maintain.
  private UserStorageInterface $repo;

  /**
   * @var HookInterface|null
   */
  // This interface is used to define hooks that can be triggered at various points in the authentication
  // process. Hooks allow for custom behavior to be executed before or after certain actions, such
  // as user registration, login, logout, and updates.
  // The Auth class uses this interface to call hooks defined by the user, allowing for extensibility
  // and customization of the authentication flow.
  // If a hook is provided, it will be called at appropriate points in the authentication process.
  // If no hook is provided, the Auth class will not call any hooks, and the default behavior will be used.
  // The HookInterface allows for methods like onBeforeRegister, onBeforeLogin, onRegisterSuccess,
  // onLoginSuccess, onLogout, and onUpdate to be defined.
  // These methods can return a boolean or a string to indicate whether the action should proceed,
  // or to provide a custom error message.
  // The Auth class checks if the hook is set and if the method exists before calling it.
  // This allows for optional hooks that can be implemented by the user without affecting the core
  // functionality of the Auth class.
  // If the hook is not set, the Auth class will not call any hooks, and the default behavior will be used.
  private ?HookInterface $hook;

  /**
   * @var int
   */
  // This property defines the time-to-live (TTL) for the session token in seconds.
  // It determines how long the session token will remain valid before it expires.
  // If the TTL is set to 0, the session will not expire.
  // If the TTL is set to a positive value, the session will expire after that many seconds.
  private int $ttlSeconds;

  /**
   * @var MessageProviderInterface
   */
  // This interface is used to provide custom error messages for various authentication-related events.
  // It allows for flexibility in defining error messages that can be used throughout the Auth class.
  // If a custom message provider is not provided, the Auth class will use the DefaultMessageProvider,
  // which provides default error messages for common authentication scenarios.
  private MessageProviderInterface $messages;

  /**
   * @var bool
   */
  // This property determines whether exceptions should be thrown on authentication errors.
  // If set to true, the Auth class will throw AuthException instances when an error occurs.
  // If set to false, the Auth class will return error messages instead of throwing exceptions. 
  private bool $throwExceptions = false;

  /**
   * @var string
   */
  // This property defines the session key used to store the authentication token in the session. 
  private string $sessionKey = 'auth_token';

  /**
   * Auth constructor.
   *
   * @param UserStorageInterface $repo
   * @param HookInterface|null $hook
   * @param int $ttlSeconds Time to live for the session token in seconds. Default is 3600 (1 hour).
   * @param MessageProviderInterface|null $messages Custom message provider for error messages.
   * @param bool $throwExceptions Whether to throw exceptions on errors or return error messages.
   */
  // If $ttlSeconds is 0, the session will not expire.
  // If $ttlSeconds is negative, the session will never expire.
  // If $ttlSeconds is positive, the session will expire after that many seconds.
  // If $ttlSeconds is not set, it defaults to 3600 seconds (1 hour).
  // If $messages is not set, it defaults to DefaultMessageProvider.
  // If $hook is not set, no hooks will be called.
  // If $throwExceptions is true, exceptions will be thrown on errors.
  // If $throwExceptions is false, error messages will be returned instead of exceptions.
  // If session is not started, it will be started automatically.
  // The session key can be set using setSessionKey() method.
  // The session key defaults to 'auth_token'.
  // The session key can be used to store the session token in the session.
  // The session key can be changed using setSessionKey() method.
  // The session key is used to store the session token in the session.
  // The session key is used to retrieve the session token from the session.
  // The session key is used to check if the user is logged in.
  // The session key is used to get the user from the session.
  // The session key is used to logout the user.
  // The session key is used to update the user in the session.
  // The session key is used to register the user in the session.
  // The session key is used to login the user in the session.
  // The session key is used to check if the user is registered in the session.
  // The session key is used to check if the user is logged in the session.
  // The session key is used to check if the user is active in the session.   
  public function __construct(
    UserStorageInterface $repo,
    ?HookInterface $hook = null,
    int $ttlSeconds = 3600,
    ?MessageProviderInterface $messages = null,
    bool $throwExceptions = false
  ) {
    $this->repo = $repo;
    $this->hook = $hook;
    $this->ttlSeconds = $ttlSeconds;
    $this->messages = $messages ?? new DefaultMessageProvider();
    $this->throwExceptions = $throwExceptions;

    if (session_status() === PHP_SESSION_NONE) {
      session_start();
    }
  }


  /**
   * Sets whether exceptions should be thrown on authentication errors.
   *
   * @param bool $value True to throw exceptions, false to suppress them.
   *
   * @return void
   */
  public function setThrowExceptions(bool $value): void
  {
    $this->throwExceptions = $value;
  }

  /**
   * Sets the session key used to store the authentication token.
   *
   * @param string $key The session key to use.
   *
   * @return void
   */
  public function setSessionKey(string $key): void
  {
    $this->sessionKey = $key;
  }

  /**
   * Handles failure cases by either throwing an exception or returning an error message.
   *
   * @param string $message The error message to return or throw.
   * @param callable|null $hookCallback Optional callback to execute on failure.
   *
   * @return string The error message if exceptions are not thrown.
   * @throws AuthException If exceptions are enabled.
   */
  // This method is used to handle errors in a consistent way.
  // It takes an error message and an optional callback.
  // If the callback is provided, it will be called with an AuthException.
  // If exceptions are enabled, it will throw an AuthException with the message.
  // If exceptions are not enabled, it will return the error message.
  // This allows the caller to handle errors in a consistent way, either by catching exceptions or
  // by checking the return value for an error message.
  // The callback can be used to perform additional actions on failure, such as logging the error
  // or notifying the user.
  // The callback is called with an AuthException instance, which contains the error message.
  // This allows the callback to access the error message and perform additional actions based on it.
  // If the callback is not provided, it will not be called.
  // This method is used in various places in the Auth class to handle errors consistently.
  // It is used in the register() and login() methods to handle errors during user registration
  // and login.
  private function fail(string $message, ?callable $hookCallback = null): string
  {
    if ($hookCallback !== null) {
      $hookCallback(new AuthException($message));
    }

    if ($this->throwExceptions) {
      throw new AuthException($message);
    }

    return $message;
  }

  /**
   * Registers a new user with the provided email and password.
   *
   * @param string $email The user's email address.
   * @param string $password The user's password.
   * @param array $customFields Optional custom fields to store with the user.
   * @param array $additionalChecks Optional additional checks to perform before registration.
   *
   * @return User|string|null Returns the created User object, or an error message if registration fails.
   */
  // The $customFields parameter allows you to store additional user data during registration.
  // The $additionalChecks parameter allows you to perform custom validation or checks before registration.
  // If the user already exists, it returns an error message.
  // If the registration is blocked by a hook or additional check, it returns an error message.
  // If the password hashing fails, it returns an error message.  
  public function register(string $email, string $password, array $customFields = [], array $additionalChecks = []): User|string|null
  {
    if ($this->repo->findByEmail($email)) {
      return $this->fail(
        $this->messages->userAlreadyExists(),
        fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
          ? $this->hook->onRegisterFailure($email, $ex)
          : null
      );
    }

    if ($this->hook && method_exists($this->hook, 'onBeforeRegister')) {
      $result = $this->hook->onBeforeRegister($email, $password, $customFields);
      if ($result !== true) {
        $message = is_string($result) ? $result : $this->messages->registrationBlocked();
        return $this->fail(
          $message,
          fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
            ? $this->hook->onRegisterFailure($email, $ex)
            : null
        );
      }
    }

    foreach ($additionalChecks as $check) {
      if (!is_callable($check)) {
        return $this->fail(
          "Additional check is not callable.",
          fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
            ? $this->hook->onRegisterFailure($email, $ex)
            : null
        );
      }

      $result = $check($email, $password, $customFields);
      if ($result !== true) {
        $message = is_string($result) ? $result : $this->messages->registrationBlocked();
        return $this->fail(
          $message,
          fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
            ? $this->hook->onRegisterFailure($email, $ex)
            : null
        );
      }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!$hash) {
      return $this->fail(
        $this->messages->passwordHashingFailed(),
        fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
          ? $this->hook->onRegisterFailure($email, $ex)
          : null
      );
    }

    try {
      $user = $this->repo->createUser($email, $hash, $customFields);
    } catch (\Throwable $e) {
      return $this->fail(
        $e->getMessage(),
        fn($ex) => $this->hook && method_exists($this->hook, 'onRegisterFailure')
          ? $this->hook->onRegisterFailure($email, $ex)
          : null
      );
    }

    if ($this->hook && method_exists($this->hook, 'onRegisterSuccess')) {
      $this->hook->onRegisterSuccess($user);
    }

    return $user;
  }

  /**
   * Logs in a user with the provided email and password.
   *
   * @param string $email The user's email address.
   * @param string $password The user's password.
   * @param array $additionalChecks Optional additional checks to perform before login.
   *
   * @return string|null Returns the session token if login is successful, or an error message if it fails.
   */
  // The $additionalChecks parameter allows you to perform custom validation or checks before login.
  // If the user is not found or the password is incorrect, it returns an error message.
  // If the login is blocked by a hook or additional check, it returns an error message.
  // If the session token is successfully created, it returns the token.
  // If the session token is not created, it returns an error message.
  // If the user is already logged in, it returns the existing session token. x
  public function login(string $email, string $password, array $additionalChecks = []): string|null
  {
    $user = $this->repo->findByEmail($email);

    if (!$user || !password_verify($password, $user->get('password_hash'))) {
      return $this->fail(
        $this->messages->invalidCredentials(),
        fn($e) => $this->hook && method_exists($this->hook, 'onLoginFailure')
          ? $this->hook->onLoginFailure($email, $e)
          : null
      );
    }

    if ($this->hook && method_exists($this->hook, 'onBeforeLogin')) {
      $result = $this->hook->onBeforeLogin($user);
      if ($result !== true) {
        $message = is_string($result) ? $result : $this->messages->loginBlocked();
        return $this->fail(
          $message,
          fn($e) => $this->hook && method_exists($this->hook, 'onLoginFailure')
            ? $this->hook->onLoginFailure($email, $e)
            : null
        );
      }
    }

    foreach ($additionalChecks as $check) {
      if (!is_callable($check)) {
        return $this->fail(
          "Additional check is not callable.",
          fn($e) => $this->hook && method_exists($this->hook, 'onLoginFailure')
            ? $this->hook->onLoginFailure($email, $e)
            : null
        );
      }

      $result = $check($user);
      if ($result !== true) {
        $message = is_string($result) ? $result : $this->messages->loginBlocked();
        return $this->fail(
          $message,
          fn($e) => $this->hook && method_exists($this->hook, 'onLoginFailure')
            ? $this->hook->onLoginFailure($email, $e)
            : null
        );
      }
    }

    $token = Uuid::uuid4()->toString();
    $expiresAt = $this->ttlSeconds > 0 ? (new DateTime())->add(new DateInterval("PT{$this->ttlSeconds}S")) : null;

    $this->repo->storeToken($user, $token, $expiresAt);

    if ($this->hook && method_exists($this->hook, 'onLoginSuccess')) {
      $this->hook->onLoginSuccess($user);
    }

    $_SESSION[$this->sessionKey] = $token;

    return $token;
  }

  /**
   * Checks if the user is currently logged in.
   *
   * @return bool Returns true if the user is logged in, false otherwise.
   */
  public function isLoggedIn(): bool
  {
    return $this->getUser() !== null;
  }

  /**
   * Retrieves the currently logged-in user.
   *
   * @return User|null Returns the User object if logged in, or null if not.
   */
  public function getUser(): ?User
  {
    if (empty($_SESSION[$this->sessionKey])) {
      return null;
    }

    $token  = (string) $_SESSION[$this->sessionKey];
    $user   = $this->repo->findByToken($token);

    if (!$user) {
      // Treat as expired/invalid session: cleanup + optional hook
      unset($_SESSION[$this->sessionKey]);
      if ($this->hook && method_exists($this->hook, 'onLogoutExpired')) {
        $this->hook->onLogoutExpired();
      }
      return null;
    }

    if ($this->hook && method_exists($this->hook, 'onUserActive')) {
      $this->hook->onUserActive($user);
    }

    return $user;
  }

  /**
   * Logs out the currently logged-in user.
   *
   * @return void
   */
  // This method deletes the session token from the storage and unsets the session variable.
  // It also calls the onLogout hook if it exists.
  // If the user is not logged in, it does nothing.
  // If the user is logged in, it deletes the session token from the storage and unsets the session variable.
  public function logout(): void
  {
    $user = $this->getUser();
    if ($user && $this->hook && method_exists($this->hook, 'onLogout')) {
      $this->hook->onLogout($user);
    }

    if (!empty($_SESSION[$this->sessionKey])) {
      $this->repo->deleteToken($_SESSION[$this->sessionKey]);
    }

    unset($_SESSION[$this->sessionKey]);
  }

  /**
   * Force-logout: invalidate ALL sessions of the given user (every device).
   * Accepts User or user id. Returns number of invalidated sessions.
   */
  public function forceLogoutUser(User|int $userOrId, ?string $reason = null): int
  {
    $userId = $userOrId instanceof User ? (int)$userOrId->get('id') : (int)$userOrId;

    // Storage-level revoke
    $count = $this->repo->deleteTokensByUserId($userId);

    // If the currently logged-in session belongs to that user — also clear PHP session.
    $current = $this->getUser();
    if ($current && (int)$current->get('id') === $userId) {
      // Optional: call onLogout hook for the *current* device
      if ($this->hook && method_exists($this->hook, 'onLogout')) {
        $this->hook->onLogout($current);
      }
      unset($_SESSION[$this->sessionKey]);
    }

    // Optional: admin/audit hook
    if ($this->hook && method_exists($this->hook, 'onLogoutForced')) {
      // Call once, not per token
      // You can pass a lightweight user stub if you don't want another fetch
      $this->hook->onLogoutForced($userId, $reason, $count);
    }

    return $count;
  }

  /**
   * Force-logout by token (single device/session).
   * Returns 1 if removed, 0 otherwise.
   */
  public function forceLogoutToken(string $token, ?string $reason = null): int
  {
    // Best-effort: try to resolve user for hook
    $user = $this->repo->findByToken($token);

    $removed = $this->repo->deleteToken($token);

    // If we just killed our own current token – clear PHP session
    if (!empty($_SESSION[$this->sessionKey]) && hash_equals($_SESSION[$this->sessionKey], $token)) {
      if ($user && $this->hook && method_exists($this->hook, 'onLogout')) {
        $this->hook->onLogout($user);
      }
      unset($_SESSION[$this->sessionKey]);
    }

    if ($user && $this->hook && method_exists($this->hook, 'onLogoutForced')) {
      $this->hook->onLogoutForced((int)$user->get('id'), $reason, $removed);
    }

    return $removed;
  }

  /**
   * Convenience: force-logout by email (all sessions).
   */
  public function forceLogoutEmail(string $email, ?string $reason = null): int
  {
    $user = $this->repo->findByEmail($email);
    if (!$user) {
      return 0;
    }
    return $this->forceLogoutUser($user, $reason);
  }

  /**
   * Updates the user's information.
   *
   * @param User $user The user object to update.
   * @param array $updates Associative array of fields to update.
   *
   * @return User Returns the updated User object.
   */
  // This method updates the user information in the storage.
  // It takes a User object and an associative array of fields to update.
  // It calls the updateUser method of the storage interface.
  // If the hook is set and has an onUserUpdated method, it calls that method
  // with the updated user and the keys of the updates array.
  // It returns the updated User object.
  // The updates array can contain any fields that are allowed to be updated.
  // The User object must be a valid user that exists in the storage.
  // The updates array must contain valid fields that can be updated.
  // If the updates array is empty, it returns the original user without changes.
  // If the user does not exist in the storage, it throws an exception.
  // If the user exists but the updates array is empty, it returns the original user without changes.
  // If the user exists and the updates array is not empty, it updates the user
  // and returns the updated User object.
  // If the user exists and the updates array is not empty, it updates the user
  // and returns the updated User object.
  // If the user exists and the updates array is not empty, it updates the user
  // and returns the updated User object.
  // If the user exists and the updates array is not empty, it updates the user
  // and returns the updated User object.
  // If the user exists and the updates array is not empty, it updates the user
  // and returns the updated User object. 
  public function updateUser(User $user, array $updates): User
  {
    $updatedUser = $this->repo->updateUser($user, $updates);

    if ($this->hook && method_exists($this->hook, 'onUserUpdated')) {
      $this->hook->onUserUpdated($updatedUser, array_keys($updates));
    }

    return $updatedUser;
  }
}
