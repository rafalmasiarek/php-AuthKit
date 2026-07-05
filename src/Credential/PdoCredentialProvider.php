<?php

declare(strict_types=1);

namespace AuthKit\Credential;

use AuthKit\Password\PasswordHasherInterface;
use AuthKit\Storage\UserStorageInterface;

/**
 * PDO-backed credential provider for email + password authentication.
 *
 * Looks up the user by email, verifies the password via the configured hasher,
 * and automatically re-hashes the stored password if the hasher signals it is outdated.
 *
 * @package AuthKit\Credential
 */
final class PdoCredentialProvider implements CredentialProviderInterface
{
    /**
     * @param UserStorageInterface    $storage User persistence layer.
     * @param PasswordHasherInterface $hasher  Password hashing and verification.
     */
    public function __construct(
        private readonly UserStorageInterface    $storage,
        private readonly PasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @inheritDoc
     *
     * @param array{email: string, password: string} $credentials
     */
    public function verify(array $credentials): CredentialResult
    {
        $email    = (string) ($credentials['email'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        $user = $this->storage->findByEmail($email);

        if ($user === null) {
            return CredentialResult::failure('Invalid credentials.');
        }

        $hash = (string) $user->get('password_hash');

        if (!$this->hasher->verify($password, $hash)) {
            return CredentialResult::failure('Invalid credentials.');
        }

        if ($this->hasher->needsRehash($hash)) {
            $user = $this->storage->updateUser($user, [
                'password_hash' => $this->hasher->hash($password),
            ]);
        }

        return CredentialResult::success($user);
    }
}
