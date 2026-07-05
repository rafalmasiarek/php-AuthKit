<?php

declare(strict_types=1);

namespace AuthKit;

use AuthKit\Challenge\ChallengeRecord;
use AuthKit\Challenge\ChallengeStorageInterface;
use AuthKit\Clock\ClockInterface;
use AuthKit\Clock\SystemClock;
use AuthKit\Credential\CredentialProviderInterface;
use AuthKit\Credential\PdoCredentialProvider;
use AuthKit\Exception\AuthException;
use AuthKit\Extension\ChallengeExtensionInterface;
use AuthKit\Extension\LoginExtensionInterface;
use AuthKit\Extension\SchemaProviderInterface;
use AuthKit\Hook\HookInterface;
use AuthKit\Message\DefaultMessageProvider;
use AuthKit\Message\MessageProviderInterface;
use AuthKit\Password\NativePasswordHasher;
use AuthKit\Password\PasswordHasherInterface;
use AuthKit\Storage\SchemaMigrationInterface;
use AuthKit\Storage\UserStorageInterface;
use AuthKit\Transport\PhpSessionTransport;
use AuthKit\Transport\TokenTransportInterface;
use DateInterval;

/**
 * Core authentication facade.
 *
 * Handles registration, login (credential verification + extension pipeline),
 * session lifecycle, and optional multi-step challenge flows (MFA, email activation, etc.).
 *
 * @package AuthKit
 */
final class Auth
{
    /**
     * @var bool Whether AuthException is thrown on errors instead of returning a message.
     */
    private bool $throwExceptions;

    /**
     * @var MessageProviderInterface
     */
    private MessageProviderInterface $messages;

    /**
     * @var PasswordHasherInterface Used by register() to hash new passwords.
     */
    private PasswordHasherInterface $hasher;

    /**
     * @var CredentialProviderInterface Used by login() to verify credentials.
     */
    private CredentialProviderInterface $credentials;

    /**
     * @var TokenTransportInterface Handles reading and writing the active token.
     */
    private TokenTransportInterface $transport;

    /**
     * @var ClockInterface Used for session and challenge expiry calculations.
     */
    private ClockInterface $clock;

    /**
     * @var array<LoginExtensionInterface> Evaluated in order after credentials pass.
     */
    private array $loginExtensions = [];

    /**
     * @param UserStorageInterface             $storage          User persistence layer.
     * @param HookInterface|null               $hook             Optional lifecycle hooks.
     * @param int                              $ttlSeconds       Session TTL in seconds (0 = no expiry).
     * @param MessageProviderInterface|null    $messages         Custom error messages.
     * @param bool                             $throwExceptions  Throw AuthException on errors.
     * @param PasswordHasherInterface|null     $hasher           Hasher for register() (default: NativePasswordHasher).
     * @param CredentialProviderInterface|null $credentials      Credential verifier for login() (default: PdoCredentialProvider).
     * @param ChallengeStorageInterface|null   $challengeStorage Required only when using challenge extensions.
     * @param TokenTransportInterface|null     $transport        Token read/write transport (default: PhpSessionTransport).
     * @param ClockInterface|null              $clock            Custom clock (takes precedence over $timezone when provided).
     * @param string                           $timezone         Timezone for the built-in SystemClock (e.g. 'Europe/Warsaw'). Ignored when $clock is provided. Defaults to UTC.
     */
    public function __construct(
        private readonly UserStorageInterface       $storage,
        private readonly ?HookInterface             $hook             = null,
        private readonly int                        $ttlSeconds       = 3600,
        ?MessageProviderInterface                   $messages         = null,
        bool                                        $throwExceptions  = false,
        ?PasswordHasherInterface                    $hasher           = null,
        ?CredentialProviderInterface                $credentials      = null,
        private readonly ?ChallengeStorageInterface $challengeStorage = null,
        ?TokenTransportInterface                    $transport        = null,
        ?ClockInterface                             $clock            = null,
        string                                      $timezone         = 'UTC',
    ) {
        $this->messages        = $messages ?? new DefaultMessageProvider();
        $this->throwExceptions = $throwExceptions;
        $this->hasher          = $hasher ?? new NativePasswordHasher();
        $this->credentials     = $credentials ?? new PdoCredentialProvider($storage, $this->hasher);
        $this->transport       = $transport ?? new PhpSessionTransport();
        $this->clock           = $clock ?? new SystemClock($timezone);
        $this->transport->initialize();
    }

    /**
     * Register a login extension to be evaluated after credentials pass.
     *
     * Extensions are evaluated in registration order.
     * The first deny or challenge decision terminates the pipeline.
     *
     * @param  LoginExtensionInterface $extension
     * @return void
     */
    public function addLoginExtension(LoginExtensionInterface $extension): void
    {
        $this->loginExtensions[] = $extension;
    }

    /**
     * Bootstrap the database schema for the configured storage backend.
     *
     * Delegates to the storage layer if it implements SchemaMigrationInterface.
     * Schema providers are collected from two sources:
     *
     *  1. Registered login extensions that implement SchemaProviderInterface
     *     (e.g. SuspendedUserExtension, ActiveUserExtension).
     *  2. Any additional providers passed explicitly — use this for components
     *     that are not login extensions but own database tables (e.g. Mailer).
     *
     * Does nothing when the active storage does not support schema migration
     * (e.g. an in-memory test stub).
     *
     * Typical bootstrap sequence:
     *
     *   $auth = new Auth(new PdoUserStorage($pdo, new UuidUserIdPolicy()), ...);
     *   $auth->addLoginExtension(new SuspendedUserExtension());
     *   $auth->addLoginExtension(new ActiveUserExtension());
     *   $auth->createSchema($mailer); // extensions + Mailer mail_tracking table
     *
     * @param  SchemaProviderInterface ...$additionalProviders Components outside the
     *         login extension pipeline that also need tables (e.g. Mailer).
     * @return void
     */
    public function createSchema(SchemaProviderInterface ...$additionalProviders): void
    {
        if (!$this->storage instanceof SchemaMigrationInterface) {
            return;
        }

        $fromExtensions = array_values(array_filter(
            $this->loginExtensions,
            static fn($ext) => $ext instanceof SchemaProviderInterface,
        ));

        $this->storage->createSchema(...$fromExtensions, ...$additionalProviders);
    }

    /**
     * Set whether AuthException is thrown on authentication errors.
     *
     * @param  bool $value
     * @return void
     */
    public function setThrowExceptions(bool $value): void
    {
        $this->throwExceptions = $value;
    }

    /**
     * Register a new user.
     *
     * @param  string               $email           User's email address.
     * @param  string               $password        Plain-text password.
     * @param  array<string, mixed> $customFields    Additional fields to persist.
     * @param  array<callable>      $additionalChecks Legacy pre-registration callbacks.
     * @return User|string|null Created User on success, or an error message on failure.
     * @throws AuthException When $throwExceptions is true and an error occurs.
     */
    public function register(string $email, string $password, array $customFields = [], array $additionalChecks = []): User|string|null
    {
        if ($this->storage->findByEmail($email)) {
            return $this->fail(
                $this->messages->userAlreadyExists(),
                fn($ex) => $this->callHook('onRegisterFailure', $email, $ex),
            );
        }

        $beforeResult = $this->callHookResult('onBeforeRegister', $email, $password, $customFields);
        if ($beforeResult !== true) {
            $message = is_string($beforeResult) ? $beforeResult : $this->messages->registrationBlocked();
            return $this->fail($message, fn($ex) => $this->callHook('onRegisterFailure', $email, $ex));
        }

        foreach ($additionalChecks as $check) {
            $result = $check($email, $password, $customFields);
            if ($result !== true) {
                $message = is_string($result) ? $result : $this->messages->registrationBlocked();
                return $this->fail($message, fn($ex) => $this->callHook('onRegisterFailure', $email, $ex));
            }
        }

        try {
            $user = $this->storage->createUser($email, $this->hasher->hash($password), $customFields);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), fn($ex) => $this->callHook('onRegisterFailure', $email, $ex));
        }

        $this->callHook('onRegisterSuccess', $user);

        return $user;
    }

    /**
     * Verify credentials and run the login extension pipeline.
     *
     * Returns a Login in one of three states:
     *  - success        : session created, token available.
     *  - failure        : credentials invalid or an extension denied.
     *  - challengeRequired : credentials valid but an extension requires an extra step.
     *
     * @param  string $email    User's email address.
     * @param  string $password Plain-text password.
     * @return Login
     * @throws AuthException When $throwExceptions is true and login is rejected.
     */
    public function login(string $email, string $password): Login
    {
        $credentialContext = [
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $result = $this->credentials->verify(
            ['email' => $email, 'password' => $password],
            $credentialContext,
        );

        if (!$result->isSuccess()) {
            $this->callHook('onLoginFailure', $email, new AuthException($result->message()));
            return $this->loginFailure($result->message());
        }

        $user    = $result->user();
        $context = new LoginContext(
            user:           $user,
            ip:             $credentialContext['ip'],
            userAgent:      $credentialContext['user_agent'],
            credentialMeta: $result->meta(),
        );

        $beforeResult = $this->callHookResult('onBeforeLogin', $user);
        if ($beforeResult !== true) {
            $message = is_string($beforeResult) ? $beforeResult : $this->messages->loginBlocked();
            $this->callHook('onLoginFailure', $email, new AuthException($message));
            return $this->loginFailure($message);
        }

        foreach ($this->loginExtensions as $extension) {
            $decision = $extension->decide($context);

            if ($decision->isDeny()) {
                $message = $decision->reason() ?? $this->messages->loginBlocked();
                $this->callHook('onLoginFailure', $email, new AuthException($message));
                return $this->loginFailure($message);
            }

            if ($decision->isChallenge()) {
                return $this->issueChallenge($user, $context, $decision);
            }
        }

        return $this->createSession($user);
    }

    /**
     * Complete a pending challenge step and create a session on success.
     *
     * @param  string               $challengeToken Raw token stored client-side (e.g. $_SESSION['authkit_challenge']).
     * @param  array<string, mixed> $input          User-supplied verification data.
     * @return Login
     * @throws AuthException        When $throwExceptions is true and verification fails.
     * @throws \RuntimeException    When no ChallengeStorageInterface is configured.
     */
    public function completeChallenge(string $challengeToken, array $input): Login
    {
        if ($this->challengeStorage === null) {
            throw new \RuntimeException('A ChallengeStorageInterface must be provided to use challenge flows.');
        }

        $challenge = $this->challengeStorage->findByToken($challengeToken);

        if ($challenge === null || $challenge->isExpired() || $challenge->isCompleted()) {
            return $this->loginFailure($this->messages->invalidCredentials());
        }

        if ($challenge->isExhausted()) {
            return $this->loginFailure($this->messages->loginBlocked());
        }

        $extension = $this->findChallengeExtension($challenge->type);
        if ($extension === null) {
            return $this->loginFailure('Unsupported challenge type.');
        }

        $decision = $extension->completeChallenge($challenge, $input);

        if (!$decision->isAllow()) {
            $this->challengeStorage->incrementAttempts($challenge);
            $message = $decision->isDeny()
                ? ($decision->reason() ?? $this->messages->invalidCredentials())
                : $this->messages->invalidCredentials();
            return $this->loginFailure($message);
        }

        $this->challengeStorage->complete($challenge, $this->clock->now());

        $user = $this->storage->findById($challenge->userId);
        if ($user === null) {
            return $this->loginFailure($this->messages->invalidCredentials());
        }

        return $this->createSession($user);
    }

    /**
     * Check whether a user is currently logged in.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->getUser() !== null;
    }

    /**
     * Retrieve the currently logged-in user from the active session.
     *
     * @return User|null
     */
    public function getUser(): ?User
    {
        $token = $this->transport->get();

        if ($token === null) {
            return null;
        }

        $user = $this->storage->findByToken($token, $this->clock->now());

        if ($user === null) {
            $this->transport->clear();
            $this->callHook('onLogoutExpired');
            return null;
        }

        $this->callHook('onUserActive', $user);

        return $user;
    }

    /**
     * Log out the currently logged-in user.
     *
     * @return void
     */
    public function logout(): void
    {
        $user = $this->getUser();

        if ($user !== null) {
            $this->callHook('onLogout', $user);
        }

        $token = $this->transport->get();
        if ($token !== null) {
            $this->storage->deleteToken($token);
        }

        $this->transport->clear();
    }

    /**
     * Invalidate all sessions for a user across every device.
     *
     * @param  User|int|string $userOrId User object or ID.
     * @param  string|null     $reason   Optional audit reason passed to the hook.
     * @return int Number of invalidated sessions.
     */
    public function forceLogoutUser(User|int|string $userOrId, ?string $reason = null): int
    {
        $userId = $userOrId instanceof User ? $userOrId->getId() : $userOrId;
        $count  = $this->storage->deleteTokensByUserId($userId);

        $current = $this->getUser();
        if ($current !== null && $current->getId() === $userId) {
            $this->callHook('onLogout', $current);
            $this->transport->clear();
        }

        $this->callHook('onLogoutForced', $userId, $reason, $count);

        return $count;
    }

    /**
     * Invalidate a single session by its token.
     *
     * @param  string      $token  Session token to revoke.
     * @param  string|null $reason Optional audit reason.
     * @return int 1 if removed, 0 otherwise.
     */
    public function forceLogoutToken(string $token, ?string $reason = null): int
    {
        $user    = $this->storage->findByToken($token, $this->clock->now());
        $removed = $this->storage->deleteToken($token);

        $current = $this->transport->get();
        if ($current !== null && hash_equals($current, $token)) {
            if ($user !== null) {
                $this->callHook('onLogout', $user);
            }
            $this->transport->clear();
        }

        if ($user !== null) {
            $this->callHook('onLogoutForced', $user->getId(), $reason, $removed);
        }

        return $removed;
    }

    /**
     * Invalidate all sessions for a user identified by email.
     *
     * @param  string      $email
     * @param  string|null $reason Optional audit reason.
     * @return int Number of invalidated sessions.
     */
    public function forceLogoutEmail(string $email, ?string $reason = null): int
    {
        $user = $this->storage->findByEmail($email);

        return $user !== null ? $this->forceLogoutUser($user, $reason) : 0;
    }

    /**
     * Update fields on a user record.
     *
     * @param  User                 $user    User to update.
     * @param  array<string, mixed> $updates Fields to update.
     * @return User Updated user.
     */
    public function updateUser(User $user, array $updates): User
    {
        $updated = $this->storage->updateUser($user, $updates);
        $this->callHook('onUserUpdated', $updated, array_keys($updates));

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create a session, persist the token, and store it in $_SESSION.
     *
     * @param  User $user
     * @return Login
     */
    private function createSession(User $user): Login
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = $this->ttlSeconds > 0
            ? $this->clock->now()->add(new DateInterval("PT{$this->ttlSeconds}S"))
            : null;

        $this->storage->storeToken($user, $token, $expiresAt);
        $this->callHook('onLoginSuccess', $user);

        $this->transport->set($token);

        return Login::success($token, $user);
    }

    /**
     * Store a ChallengeRecord and return a challengeRequired Login.
     *
     * @param  User          $user
     * @param  LoginContext  $context
     * @param  LoginDecision $decision
     * @return Login
     * @throws \RuntimeException When no ChallengeStorageInterface is configured.
     */
    private function issueChallenge(User $user, LoginContext $context, LoginDecision $decision): Login
    {
        if ($this->challengeStorage === null) {
            throw new \RuntimeException('A ChallengeStorageInterface must be provided to use challenge flows.');
        }

        $rawToken        = bin2hex(random_bytes(32));
        $type            = (string) $decision->challengeType();
        $expiresAtSource = $decision->challengeExpiresAt();
        $expiresAt       = $expiresAtSource !== null
            ? \DateTime::createFromInterface($expiresAtSource)
            : \DateTime::createFromInterface($this->clock->now()->add(new DateInterval('PT15M')));

        $record = new ChallengeRecord(
            id:          bin2hex(random_bytes(16)),
            userId:      $user->getId() ?? '',
            type:        $type,
            payload:     $decision->challengePayload(),
            maxAttempts: $decision->challengeMaxAttempts() ?? 5,
            expiresAt:   $expiresAt,
            ip:          $context->ip,
            userAgent:   $context->userAgent,
        );

        $this->challengeStorage->store($record, $rawToken);

        $message = $decision->challengeMessage();

        return Login::challengeRequired($rawToken, $type, $user, $message);
    }

    /**
     * Find the first registered extension that handles the given challenge type.
     *
     * @param  string $type
     * @return ChallengeExtensionInterface|null
     */
    private function findChallengeExtension(string $type): ?ChallengeExtensionInterface
    {
        foreach ($this->loginExtensions as $extension) {
            if ($extension instanceof ChallengeExtensionInterface && $extension->supportsChallenge($type)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * Return Login::failure() and optionally throw AuthException.
     *
     * @param  string $message
     * @return Login
     * @throws AuthException
     */
    private function loginFailure(string $message): Login
    {
        if ($this->throwExceptions) {
            throw new AuthException($message);
        }

        return Login::failure($message);
    }

    /**
     * Handle register-time failure: invoke hook callback, then throw or return message.
     *
     * @param  string        $message
     * @param  callable|null $hookCallback
     * @return string
     * @throws AuthException
     */
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
     * Call a hook method if the hook is configured and the method exists.
     *
     * @param  string $method
     * @param  mixed  ...$args
     * @return void
     */
    private function callHook(string $method, mixed ...$args): void
    {
        if ($this->hook !== null && method_exists($this->hook, $method)) {
            $this->hook->{$method}(...$args);
        }
    }

    /**
     * Call a hook method that returns a pass/fail result.
     *
     * Returns true when no hook is configured (meaning: proceed).
     *
     * @param  string $method
     * @param  mixed  ...$args
     * @return mixed
     */
    private function callHookResult(string $method, mixed ...$args): mixed
    {
        if ($this->hook !== null && method_exists($this->hook, $method)) {
            return $this->hook->{$method}(...$args);
        }

        return true;
    }
}
