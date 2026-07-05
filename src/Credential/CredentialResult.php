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
     * @param bool                 $success Whether credentials were valid.
     * @param User|null            $user    Authenticated user, or null on failure.
     * @param string               $message Failure reason, or empty string on success.
     * @param array<string, mixed> $meta    Provider-specific metadata (e.g. provider name, JWT claims).
     */
    private function __construct(
        private readonly bool   $success,
        private readonly ?User  $user,
        private readonly string $message,
        private readonly array  $meta = [],
    ) {
    }

    /**
     * Create a successful result with the verified user.
     *
     * @param  User                 $user Authenticated user.
     * @param  array<string, mixed> $meta Optional provider metadata passed to the login extension pipeline.
     * @return self
     */
    public static function success(User $user, array $meta = []): self
    {
        return new self(true, $user, '', $meta);
    }

    /**
     * Create a failure result with a reason message.
     *
     * @param  string               $message Human-readable failure reason.
     * @param  array<string, mixed> $meta    Optional provider metadata (e.g. for audit logging).
     * @return self
     */
    public static function failure(string $message, array $meta = []): self
    {
        return new self(false, null, $message, $meta);
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

    /**
     * Return provider metadata or a single key from it.
     *
     * With no argument, returns the full metadata array.
     * With a key, returns that value or $default when not present.
     *
     * @param  string|null $key     Key to retrieve, or null for the full array.
     * @param  mixed       $default Returned when the key is not found.
     * @return mixed
     */
    public function meta(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->meta;
        }

        return $this->meta[$key] ?? $default;
    }
}
