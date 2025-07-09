<?php

namespace AuthKit\Message;

interface MessageProviderInterface
{
    public function userNotFound(): string;
    public function userAlreadyExists(): string;
    public function passwordHashingFailed(): string;
    public function invalidCredentials(): string;
    public function registrationBlocked(): string;
    public function loginBlocked(): string;
}
