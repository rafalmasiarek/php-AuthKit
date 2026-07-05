<?php

declare(strict_types=1);

namespace AuthKit\Clock;

/**
 * Provides the current time as an immutable value object.
 *
 * Compatible with the PSR-20 clock contract — any PSR-20 implementation
 * satisfies this interface without modification.
 *
 * @package AuthKit\Clock
 */
interface ClockInterface
{
    /**
     * Return the current time.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable;
}
