<?php

declare(strict_types=1);

namespace AuthKit;

/**
 * Immutable context passed to every LoginExtensionInterface::decide() call.
 *
 * Built after successful credential verification, before session creation.
 * Contains everything an extension needs to make its allow/deny/challenge decision.
 *
 * @package AuthKit
 */
final class LoginContext
{
    /**
     * @param User                 $user           Authenticated user (credentials verified, no session yet).
     * @param string               $ip             Client IP address from the request.
     * @param string               $userAgent      Client User-Agent header value.
     * @param array<string, mixed> $credentialMeta Provider-specific metadata from CredentialResult (e.g. provider name, JWT claims).
     */
    public function __construct(
        public readonly User   $user,
        public readonly string $ip             = '',
        public readonly string $userAgent      = '',
        public readonly array  $credentialMeta = [],
    ) {
    }

    /**
     * Return a single value from credential metadata by key.
     *
     * @param  string $key
     * @param  mixed  $default Returned when the key is not found.
     * @return mixed
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return $this->credentialMeta[$key] ?? $default;
    }

    /**
     * Return all credential metadata from the provider.
     *
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return $this->credentialMeta;
    }
}
