<?php

namespace AuthKit\Hook;

use AuthKit\User;
use AuthKit\Exception\AuthException;

interface HookInterface
{
    /**
     * Called before registration, can return:
     * - true to allow registration
     * - string with error message to block
     * - false to use default error message
     */
    public function onBeforeRegister(string $email, string $password, array $fields): true|string;

    /**
     * Called after successful registration.
     */
    public function onRegisterSuccess(User $user): void;

    /**
     * Called when registration fails due to exception or pre-check.
     */
    public function onRegisterFailure(string $email, AuthException $e): void;

    /**
     * Called before login. Can block login.
     */
    public function onBeforeLogin(User $user): true|string;

    /**
     * Called after successful login.
     */
    public function onLoginSuccess(User $user): void;

    /**
     * Called when login fails (email not found or password mismatch).
     */
    public function onLoginFailure(string $email, AuthException $e): void;

    /**
     * Called when user is fetched via getUser() and token is valid.
     */
    public function onUserActive(User $user): void;

    /**
     * Called when session expires due to TTL.
     */
    public function onLogoutExpired(): void;

    /**
     * Called when logout() is called explicitly.
     */
    public function onLogout(User $user): void;

    /**
     * Called when sessions are forcibly invalidated by admin or security action.
     * $count = how many sessions were removed.
     */
    public function onLogoutForced(int $userId, ?string $reason, int $count): void;

    /**
     * Called after updating user data.
     * $changedFields are the keys that were modified.
     */
    public function onUserUpdated(User $user, array $changedFields): void;
}
