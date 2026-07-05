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
     * @param User                 $user      Authenticated user (credentials verified, no session yet).
     * @param string               $ip        Client IP address from the request.
     * @param string               $userAgent Client User-Agent header value.
     * @param array<string, mixed> $meta      Optional extra data set by the credential provider or caller.
     */
    public function __construct(
        public readonly User   $user,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly array  $meta = [],
    ) {
    }
}
