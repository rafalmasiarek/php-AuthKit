<?php

declare(strict_types=1);

namespace AuthKit\Transport;

/**
 * Abstracts how the active session token is stored and retrieved on the client side.
 *
 * Implement this interface to swap the default PHP session transport for any
 * other mechanism (Bearer header, cookie, in-memory, etc.).
 *
 * @package AuthKit\Transport
 */
interface TokenTransportInterface
{
    /**
     * Called once during Auth initialization.
     *
     * Use this to start a session, open storage, or perform any setup required
     * before the transport can be used.
     *
     * @return void
     */
    public function initialize(): void;

    /**
     * Return the current token, or null if none is present.
     *
     * @return string|null
     */
    public function get(): ?string;

    /**
     * Store the token after a successful login.
     *
     * @param  string $token
     * @return void
     */
    public function set(string $token): void;

    /**
     * Remove the stored token on logout.
     *
     * @return void
     */
    public function clear(): void;
}
