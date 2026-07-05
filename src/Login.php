<?php

declare(strict_types=1);

namespace AuthKit;

/**
 * Immutable result of an Auth::login() call.
 *
 * Three possible states:
 *  - success   : credentials verified, session created, token available.
 *  - failure   : authentication rejected, message available.
 *  - challenge : credentials verified but an extension requires an additional
 *                verification step (MFA, email activation, device check, etc.).
 *                No session is created until Auth::completeChallenge() succeeds.
 *
 * Usage (simple flow):
 *   $login = $auth->login($email, $password);
 *   if ($login->isSuccess()) {
 *       header('Location: /dashboard');
 *   }
 *   echo $login->message();
 *
 * Usage (challenge flow, e.g. MFA):
 *   $login = $auth->login($email, $password);
 *   if ($login->requiresChallenge()) {
 *       $_SESSION['authkit_challenge'] = $login->challengeToken();
 *       header('Location: /login/challenge/' . $login->challengeType());
 *   }
 *
 * @package AuthKit
 */
final class Login
{
    private const STATUS_SUCCESS   = 'success';
    private const STATUS_FAILURE   = 'failure';
    private const STATUS_CHALLENGE = 'challenge';

    private function __construct(
        private readonly string  $status,
        private readonly string  $message,
        private readonly ?string $token          = null,
        private readonly ?User   $user           = null,
        private readonly ?string $challengeToken = null,
        private readonly ?string $challengeType  = null,
    ) {
    }

    /**
     * Create a successful login result with a session token.
     *
     * @param string $token Session token stored in DB and $_SESSION.
     * @param User   $user  Authenticated user.
     * @return self
     */
    public static function success(string $token, User $user): self
    {
        return new self(
            status:  self::STATUS_SUCCESS,
            message: '',
            token:   $token,
            user:    $user,
        );
    }

    /**
     * Create a failure result.
     *
     * @param string $message Human-readable reason shown to the user.
     * @return self
     */
    public static function failure(string $message): self
    {
        return new self(
            status:  self::STATUS_FAILURE,
            message: $message,
        );
    }

    /**
     * Create a challenge-required result.
     *
     * Credentials were verified but an extension blocked session creation
     * pending an additional step. No session exists at this point.
     *
     * @param string $challengeToken Raw token to pass to Auth::completeChallenge().
     *                               Store on the caller side (e.g. $_SESSION['authkit_challenge']).
     * @param string $challengeType  Extension-defined type identifier (e.g. 'mfa_totp', 'email_activation').
     * @param User   $user           Pre-session user — credentials verified, no session yet.
     * @return self
     */
    public static function challengeRequired(string $challengeToken, string $challengeType, User $user): self
    {
        return new self(
            status:         self::STATUS_CHALLENGE,
            message:        '',
            user:           $user,
            challengeToken: $challengeToken,
            challengeType:  $challengeType,
        );
    }

    /**
     * @return bool True when a session was created and token() is available.
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return bool True when an additional verification step is required.
     */
    public function requiresChallenge(): bool
    {
        return $this->status === self::STATUS_CHALLENGE;
    }

    /**
     * @return bool True when authentication was rejected.
     */
    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILURE;
    }

    /**
     * @return string|null Session token (set only when isSuccess() is true).
     */
    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * @return User|null Authenticated user (null on failure).
     */
    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * @return string|null Raw token to pass to completeChallenge() (set only when requiresChallenge()).
     */
    public function challengeToken(): ?string
    {
        return $this->challengeToken;
    }

    /**
     * @return string|null Challenge type identifier (set only when requiresChallenge()).
     */
    public function challengeType(): ?string
    {
        return $this->challengeType;
    }

    /**
     * @return string Error or informational message (meaningful when isFailure() or requiresChallenge()).
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return string Raw status string ('success', 'failure', 'challenge').
     */
    public function status(): string
    {
        return $this->status;
    }
}
