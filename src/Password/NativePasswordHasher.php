<?php

declare(strict_types=1);

namespace AuthKit\Password;

/**
 * Password hasher backed by PHP's native password_* functions.
 *
 * @package AuthKit\Password
 */
final class NativePasswordHasher implements PasswordHasherInterface
{
    /**
     * @param int|string $algorithm Algorithm constant passed to password_hash() (default: PASSWORD_DEFAULT).
     */
    public function __construct(
        private readonly int|string $algorithm = PASSWORD_DEFAULT,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hash(string $plain): string
    {
        return password_hash($plain, $this->algorithm);
    }

    /**
     * @inheritDoc
     */
    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * @inheritDoc
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm);
    }
}
