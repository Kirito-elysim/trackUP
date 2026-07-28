<?php
declare(strict_types=1);

namespace App\Util;

final class DurationUnit
{
    public static function secondsToMinutesFloat(mixed $seconds): float
    {
        if (!is_numeric($seconds)) {
            return 0.0;
        }

        return max(0.0, (float) $seconds / 60);
    }

    public static function secondsToMinutesInt(mixed $seconds): int
    {
        return self::minutesToInt(self::secondsToMinutesFloat($seconds));
    }

    public static function secondsToMinutesIntOrNull(mixed $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }

        return self::secondsToMinutesInt($seconds);
    }

    public static function minutesToInt(mixed $minutes): int
    {
        if (!is_numeric($minutes)) {
            return 0;
        }

        return max(0, (int) round((float) $minutes));
    }
}
