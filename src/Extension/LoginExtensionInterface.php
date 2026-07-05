<?php

declare(strict_types=1);

namespace AuthKit\Extension;

use AuthKit\LoginContext;
use AuthKit\LoginDecision;

/**
 * Evaluates whether a login attempt should proceed after credentials are verified.
 *
 * Extensions are registered via Auth::addLoginExtension() and evaluated in
 * registration order. The first deny or challenge decision terminates the pipeline.
 * A session is created only when every registered extension returns allow.
 *
 * @package AuthKit\Extension
 */
interface LoginExtensionInterface
{
    /**
     * Evaluate the login context and return a decision.
     *
     * @param  LoginContext  $context Authenticated user with request metadata.
     * @return LoginDecision allow(), deny(reason), or challenge(type, payload).
     */
    public function decide(LoginContext $context): LoginDecision;
}
