<?php

namespace LinkRobins\Referral;

use Carbon\CarbonImmutable;

final class ReferralTime
{
    public const TIMEZONE = 'Asia/Shanghai';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    public static function toDatabase(\DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->utc();
    }

    public static function fromDatabase(?\DateTimeInterface $date): ?CarbonImmutable
    {
        if (! $date) {
            return null;
        }

        // Flarum persists timestamps as UTC, but a database date cast may
        // hydrate the value with the PHP application's local timezone. Read
        // the formatted database wall time explicitly as UTC before converting
        // it to Beijing time, otherwise the offset can be applied twice.
        return CarbonImmutable::parse($date->format('Y-m-d H:i:s.u'), 'UTC')
            ->setTimezone(self::TIMEZONE);
    }

    public static function dayStartUtc(): CarbonImmutable
    {
        return self::now()->startOfDay()->utc();
    }

    public static function nextDayStartUtc(): CarbonImmutable
    {
        return self::now()->addDay()->startOfDay()->utc();
    }
}
