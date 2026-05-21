<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\ConfirmBooking;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;

final class ConfirmBookingTest extends TestCase
{
    private function pending(): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        return Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
    }

    public function test_confirms_pending_booking_and_persists(): void
    {
        $b = $this->pending();
        $b->assignId(42);

        $saved = false;
        $useCase = new ConfirmBooking(
            find: fn (int $id) => $id === 42 ? $b : null,
            persist: function (Booking $bb) use (&$saved): void { $saved = true; },
        );

        $useCase->execute(42);

        self::assertTrue($saved);
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_is_idempotent_on_already_confirmed(): void
    {
        $b = $this->pending();
        $b->assignId(42);
        $b->confirm();

        $useCase = new ConfirmBooking(
            find: fn () => $b,
            persist: fn () => null,
        );

        $useCase->execute(42);
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_throws_when_booking_missing(): void
    {
        $useCase = new ConfirmBooking(
            find: fn () => null,
            persist: fn () => null,
        );
        $this->expectException(BookingNotFound::class);
        $useCase->execute(999);
    }
}
