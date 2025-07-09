<?php

namespace AuthKit\Hook;

use AuthKit\User;
use AuthKit\Exception\AuthException;

abstract class AbstractHook implements HookInterface
{
    /**
     * @inheritDoc
     */
    public function onRegisterSuccess(User $user): void {}

    /**
     * @inheritDoc
     */
    public function onRegisterFailure(string $email, AuthException $e): void {}

    /**
     * @inheritDoc
     */
    public function onLoginSuccess(User $user): void {}

    /**
     * @inheritDoc
     */
    public function onLoginFailure(string $email, AuthException $e): void {}

    /**
     * @inheritDoc
     */
    public function onBeforeRegister(string $email, string $password, array $fields): true|string
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function onBeforeLogin(User $user): true|string
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function onLogout(User $user): void {}

    /**
     * @inheritDoc
     */
    public function onLogoutExpired(): void {}

    /**
     * @inheritDoc
     */
    public function onUpdate(User $user): void {}

    /**
     * @inheritDoc
     */
    public function onUserActive(User $user): void {}

    /**
     * @inheritDoc
     */
    public function onUserUpdated(User $user, array $changedFields): void {}
}
