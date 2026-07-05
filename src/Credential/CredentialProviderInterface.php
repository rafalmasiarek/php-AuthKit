<?php

declare(strict_types=1);

namespace AuthKit\Credential;

/**
 * Verifies raw credentials and returns the matching user on success.
 *
 * @package AuthKit\Credential
 */
interface CredentialProviderInterface
{
    /**
     * Verify the supplied credentials.
     *
     * @param  array<string, mixed> $credentials Raw input (e.g. ['email' => ..., 'password' => ...]).
     * @param  array<string, mixed> $context     Optional request context (e.g. 'ip', 'user_agent') for logging or rate-limiting.
     * @return CredentialResult
     */
    public function verify(array $credentials, array $context = []): CredentialResult;
}
