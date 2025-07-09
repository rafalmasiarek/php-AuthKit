<?php

namespace AuthKit\Message;

class DefaultMessageProvider implements MessageProviderInterface
{
    /**
     * @return string
     */
    public function userAlreadyExists(): string
    {
        return "User with this email already exists.";
    }

    /**
     * @return string
     */
    public function passwordHashingFailed(): string
    {
        return "Password hashing failed.";
    }

    /**
     * @return string
     */
    public function invalidCredentials(): string
    {
        return "Invalid email or password.";
    }

    /**
     * @return string
     */
    public function registrationBlocked(): string
    {
        return "Registration is blocked.";
    }

    /**
     * @return string
     */
    public function userNotFound(): string
    {
        return "User not found.";
    }

    /**
     * @return string
     */
    public function loginBlocked(): string
    {
        return "Login blocked by custom condition.";
    }
}
