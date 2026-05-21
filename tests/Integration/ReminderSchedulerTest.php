<?php

declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Notifications\ReminderScheduler;
use Slash\Booking\Persistence\BookingRepository;
use WP_UnitTestCase;

final class ReminderSchedulerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
    }

    public function test_fires_reminder_action_for_due_bookings(): void
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $slot = new TimeSlot(
            $now->modify('+24 hours')->setTime(10, 0),
            $now->modify('+24 hours')->setTime(11, 0),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'X', customerEmail: 'x@x.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $b->confirm();
        $repo->save($b);

        $fired = [];
        add_action('slashbooking/booking_reminder_due', static function (int $id) use (&$fired): void {
            $fired[] = $id;
        });

        (new ReminderScheduler($repo))->run();
        self::assertContains($b->id(), $fired);

        $fired = [];
        (new ReminderScheduler($repo))->run();
        self::assertSame([], $fired);
    }

    public function test_does_not_fire_for_pending_or_outside_window(): void
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // pending booking due in 24h — must NOT fire (status != confirmed)
        $slotIn24 = new TimeSlot(
            $now->modify('+24 hours')->setTime(10, 0),
            $now->modify('+24 hours')->setTime(11, 0),
        );
        $pending = Booking::createPending(
            serviceId: 1, slot: $slotIn24, timezone: 'Europe/Paris',
            customerName: 'P', customerEmail: 'p@p.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $repo->save($pending);

        // confirmed booking due in 5 days — must NOT fire (outside window)
        $slotFar = new TimeSlot(
            $now->modify('+5 days')->setTime(10, 0),
            $now->modify('+5 days')->setTime(11, 0),
        );
        $far = Booking::createPending(
            serviceId: 1, slot: $slotFar, timezone: 'Europe/Paris',
            customerName: 'F', customerEmail: 'f@f.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $far->confirm();
        $repo->save($far);

        $fired = [];
        add_action('slashbooking/booking_reminder_due', static function (int $id) use (&$fired): void {
            $fired[] = $id;
        });

        (new ReminderScheduler($repo))->run();
        self::assertSame([], $fired);
    }
}
