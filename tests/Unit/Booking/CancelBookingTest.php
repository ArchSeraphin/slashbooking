<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\CancelBooking;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class CancelBookingTest extends TestCase
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

    public function test_cancels_pending_booking(): void
    {
        $b = $this->pending();
        $b->assignId(7);
        $saved = false;
        $useCase = new CancelBooking(
            find: fn (string $uid) => $uid === $b->publicUid() ? $b : null,
            persist: function (Booking $bb) use (&$saved): void { $saved = true; },
        );
        $useCase->execute($b->publicUid());
        self::assertTrue($saved);
        self::assertSame(BookingStatus::CANCELLED, $b->status());
    }

    public function test_throws_when_booking_missing(): void
    {
        $useCase = new CancelBooking(
            find: fn (string $uid) => null,
            persist: fn (Booking $b) => null,
        );
        $this->expectException(BookingNotFound::class);
        $useCase->execute('missing-uid');
    }
}
