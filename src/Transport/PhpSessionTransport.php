<?php

declare(strict_types=1);

namespace AuthKit\Transport;

/**
 * Token transport backed by the native PHP session.
 *
 * This is the default transport for web applications. The token is stored
 * under a configurable key in $_SESSION and travels via session cookie.
 *
 * @package AuthKit\Transport
 */
final class PhpSessionTransport implements TokenTransportInterface
{
    /**
     * @param string $key $_SESSION key used to store the token (default: 'auth_token').
     */
    public function __construct(private readonly string $key = 'auth_token')
    {
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @inheritDoc
     */
    public function get(): ?string
    {
        $token = $_SESSION[$this->key] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @inheritDoc
     */
    public function set(string $token): void
    {
        $_SESSION[$this->key] = $token;
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        unset($_SESSION[$this->key]);
    }
}
