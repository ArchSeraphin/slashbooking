<?php

declare(strict_types=1);

namespace Trinity\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        foreach ([
            \Trinity\Booking\Notifications\ReminderScheduler::HOOK,
            \Trinity\Booking\Google\SyncLogPurger::HOOK,
            'tb/watch_renew_check',
            'tb/google_pull_all',
        ] as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts !== false) {
                wp_unschedule_event($ts, $hook);
            }
        }

        wp_clear_scheduled_hook(\Trinity\Booking\Privacy\BookingRetentionPurger::HOOK);

        // Note: we don't call stopChannel() here because the deactivation context
        // doesn't guarantee a fully booted plugin (no service container, possibly
        // no token). The watch channel will expire on its own within 7 days.
        // Admin can explicitly "Stop watch" from the UI before deactivating.
    }
}
