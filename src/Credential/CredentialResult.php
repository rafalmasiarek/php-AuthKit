<?php

declare(strict_types=1);

namespace AuthKit\Credential;

use AuthKit\User;

/**
 * Result of a credential verification attempt.
 *
 * @package AuthKit\Credential
 */
final class CredentialResult
{
    /**
     * @param bool      $success Whether credentials were valid.
     * @param User|null $user    Authenticated user, or null on failure.
     * @param string    $message Failure reason, or empty string on success.
     */
    private function __construct(
        private readonly bool   $success,
        private readonly ?User  $user,
        private readonly string $message,
    ) {
    }

    /**
     * Create a successful result with the verified user.
     *
     * @param  User $user Authenticated user.
     * @return self
     */
    public static function success(User $user): self
    {
        return new self(true, $user, '');
    }

    /**
     * Create a failure result with a reason message.
     *
     * @param  string $message Human-readable failure reason.
     * @return self
     */
    public static function failure(string $message): self
    {
        return new self(false, null, $message);
    }

    /**
     * @return bool True when credentials were valid.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return User|null Authenticated user, or null on failure.
     */
    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * @return string Failure reason, or empty string on success.
     */
    public function message(): string
    {
        return $this->message;
    }
}
