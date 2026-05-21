<?php

declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use WP_UnitTestCase;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;
use DateTimeImmutable;
use DateTimeZone;

final class BookingRepositoryTest extends WP_UnitTestCase
{
    private BookingRepository $bookings;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->bookings = new BookingRepository($wpdb);
        $services = new ServiceRepository($wpdb);
        $this->serviceId = $services->findBySlug('pv')->id;
    }

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private function pending(string $start, string $end, string $email = 'a@b.fr'): Booking
    {
        $slot = new TimeSlot($this->utc($start), $this->utc($end));
        return Booking::createPending(
            serviceId: $this->serviceId,
            slot: $slot,
            timezone: 'Europe/Paris',
            customerName: 'Test',
            customerEmail: $email,
            customerPhone: '0600000000',
            customerAddress: 'x',
            customerMeta: [],
            notes: '',
        );
    }

    public function test_save_assigns_id_and_reload(): void
    {
        $b = $this->pending('2026-06-01T08:00:00Z', '2026-06-01T09:30:00Z');
        $this->bookings->save($b);
        self::assertNotNull($b->id());

        $reloaded = $this->bookings->findById($b->id());
        self::assertNotNull($reloaded);
        self::assertSame('a@b.fr', $reloaded->customerEmail());
    }

    public function test_find_by_public_uid(): void
    {
        $b = $this->pending('2026-06-02T08:00:00Z', '2026-06-02T09:30:00Z');
        $this->bookings->save($b);
        $found = $this->bookings->findByPublicUid($b->publicUid());
        self::assertNotNull($found);
        self::assertSame($b->id(), $found->id());
    }

    public function test_find_overlapping_only_blocking_statuses(): void
    {
        $a = $this->pending('2026-06-03T08:00:00Z', '2026-06-03T09:30:00Z');
        $this->bookings->save($a);

        $overlapping = $this->bookings->findOverlapping(
            $this->serviceId,
            new TimeSlot($this->utc('2026-06-03T09:00:00Z'), $this->utc('2026-06-03T10:00:00Z'))
        );
        self::assertCount(1, $overlapping);

        $a->cancel();
        $this->bookings->save($a);

        $overlapping = $this->bookings->findOverlapping(
            $this->serviceId,
            new TimeSlot($this->utc('2026-06-03T09:00:00Z'), $this->utc('2026-06-03T10:00:00Z'))
        );
        self::assertCount(0, $overlapping);
    }

    public function test_find_by_google_event_id_returns_booking(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . 'sb_bookings', [
            'public_uid'       => 'uid-' . uniqid(),
            'service_id'       => $this->serviceId,
            'status'           => 'confirmed',
            'starts_at_utc'    => '2026-06-01 09:00:00',
            'ends_at_utc'      => '2026-06-01 10:30:00',
            'timezone'         => 'Europe/Paris',
            'customer_name'    => 'X',
            'customer_email'   => 'x@example.com',
            'customer_phone'   => '0600000000',
            'google_event_id'  => 'gcal_known_1',
            'created_at'       => '2026-05-20 00:00:00',
            'updated_at'       => '2026-05-20 00:00:00',
        ]);

        $found = $this->bookings->findByGoogleEventId('gcal_known_1');
        self::assertNotNull($found);

        self::assertNull($this->bookings->findByGoogleEventId('gcal_unknown_404'));
    }
}
