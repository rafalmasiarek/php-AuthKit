<?php

declare(strict_types=1);

namespace AuthKit\Transport;

/**
 * Token transport for stateless API clients using HTTP Bearer authentication.
 *
 * Reads the token from the Authorization: Bearer <token> request header.
 * set() and clear() are no-ops — the client is responsible for storing
 * and discarding the token on its own side.
 *
 * @package AuthKit\Transport
 */
final class BearerHeaderTransport implements TokenTransportInterface
{
    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
    }

    /**
     * @inheritDoc
     *
     * Extracts the token from the Authorization: Bearer <token> header.
     */
    public function get(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    /**
     * @inheritDoc
     *
     * No-op — the client stores the token on its own side.
     */
    public function set(string $token): void
    {
    }

    /**
     * @inheritDoc
     *
     * No-op — the client discards the token on its own side.
     */
    public function clear(): void
    {
    }
}
