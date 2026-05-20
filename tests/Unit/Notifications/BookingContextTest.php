<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Notifications\Events\BookingContext;

final class BookingContextTest extends TestCase
{
    public function test_serialises_booking_and_service_to_tag_dataset(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: '1 rue X',
            customerMeta: [], notes: 'RAS',
        );
        $b->assignId(42);
        $svc = new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );

        $ctx = BookingContext::fromBooking($b, $svc, [
            'site_name'    => 'Trinity',
            'site_url'     => 'https://t.tld',
            'admin_email'  => 'admin@t.tld',
            'company_phone' => '0102',
            'company_logo' => '',
            'cancel_url'   => 'https://t.tld/cancel?sig=1',
            'confirm_url'  => 'https://t.tld/decide?sig=2',
            'reject_url'   => 'https://t.tld/decide?sig=3',
            'ics_url'      => '',
        ]);

        $data = $ctx->toArray();
        self::assertSame('Jean', $data['customer_name']);
        self::assertSame('Photovoltaïque', $data['service_name']);
        self::assertSame('1h30', $data['service_duration']);
        self::assertSame('Europe/Paris', $data['timezone']);
        self::assertSame('10:00', $data['appointment_time']);
        self::assertSame('11:30', $data['appointment_end']);
        self::assertSame('Trinity', $data['site_name']);
        self::assertSame('https://t.tld/cancel?sig=1', $data['cancel_url']);
    }

    public function test_service_duration_uses_minutes_when_under_one_hour(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T08:45:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 2, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'A', customerEmail: 'a@a.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $svc = new Service(
            id: 2, slug: 'irve', name: 'IRVE', durationMin: 45,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );
        $ctx = BookingContext::fromBooking($b, $svc, []);
        self::assertSame('45 min', $ctx->toArray()['service_duration']);
    }

    public function test_extra_defaults_to_empty_strings(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'A', customerEmail: 'a@a.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $svc = new Service(
            id: 1, slug: 'pv', name: 'PV', durationMin: 90,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );
        $ctx = BookingContext::fromBooking($b, $svc, []);
        self::assertSame('', $ctx->toArray()['cancel_url']);
        self::assertSame('', $ctx->toArray()['site_name']);
    }
}
