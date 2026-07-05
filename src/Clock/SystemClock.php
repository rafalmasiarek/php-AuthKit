<?php

declare(strict_types=1);

namespace AuthKit\Clock;

/**
 * System clock — returns the current wall-clock time in the configured timezone.
 *
 * @package AuthKit\Clock
 */
final class SystemClock implements ClockInterface
{
    /**
     * @var \DateTimeZone
     */
    private readonly \DateTimeZone $tz;

    /**
     * @param \DateTimeZone|string $timezone Timezone for now(). Defaults to UTC.
     *
     * @throws \Exception When an invalid timezone string is provided.
     */
    public function __construct(\DateTimeZone|string $timezone = 'UTC')
    {
        $this->tz = is_string($timezone) ? new \DateTimeZone($timezone) : $timezone;
    }

    /**
     * @inheritDoc
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz);
    }
}
