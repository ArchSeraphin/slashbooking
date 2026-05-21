<?php

declare(strict_types=1);

namespace Slash\Booking\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Persistence\BookingRepository;

final class ReminderScheduler
{
    public const HOOK = 'sb_send_daily_reminders';

    public function __construct(private readonly BookingRepository $bookings)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $now->modify('+23 hours');
        $end   = $now->modify('+25 hours');

        foreach ($this->bookings->findRemindersDue($start, $end) as $booking) {
            $id = $booking->id();
            if ($id === null) {
                continue;
            }
            $this->bookings->markReminderSent($id, $now);
            do_action('slashbooking/booking_reminder_due', $id);
        }
    }
}
