<?php

declare(strict_types=1);

namespace Trinity\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Cron clear viendra dans Plan 2 (reminders).
        // Watch channel unsubscribe viendra dans Plan 4.
    }
}
