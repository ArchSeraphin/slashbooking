<?php

declare(strict_types=1);

namespace Slash\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        foreach ([
            \Slash\Booking\Notifications\ReminderScheduler::HOOK,
            \Slash\Booking\Google\SyncLogPurger::HOOK,
            'sb/watch_renew_check',
            'sb/google_pull_all',
        ] as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts !== false) {
                wp_unschedule_event($ts, $hook);
            }
        }

        wp_clear_scheduled_hook(\Slash\Booking\Privacy\BookingRetentionPurger::HOOK);

        // Note: we don't call stopChannel() here because the deactivation context
        // doesn't guarantee a fully booted plugin (no service container, possibly
        // no token). The watch channel will expire on its own within 7 days.
        // Admin can explicitly "Stop watch" from the UI before deactivating.
    }
}
