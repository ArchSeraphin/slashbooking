<?php

declare(strict_types=1);

namespace Trinity\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(\Trinity\Booking\Notifications\ReminderScheduler::HOOK);
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, \Trinity\Booking\Notifications\ReminderScheduler::HOOK);
        }

        // Watch channel unsubscribe viendra dans Plan 4.
    }
}
