<?php

declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class BookingTest extends TestCase
{
    private function slot(): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00', new DateTimeZone('UTC')),
        );
    }

    public function test_new_booking_is_pending(): void
    {
        $b = Booking::createPending(
            serviceId: 1,
            slot: $this->slot(),
            timezone: 'Europe/Paris',
            customerName: 'Jean Test',
            customerEmail: 'jean@test.fr',
            customerPhone: '0600000000',
            customerAddress: '1 rue X, 75001 Paris',
            customerMeta: ['housing' => 'house'],
            notes: 'Maison récente',
        );
        self::assertSame(BookingStatus::PENDING, $b->status());
        self::assertNotEmpty($b->publicUid());
        self::assertSame(1, $b->serviceId());
    }

    public function test_confirm_transitions_only_from_pending(): void
    {
        $b = $this->makePending();
        $b->confirm();
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_reject_only_from_pending(): void
    {
        $b = $this->makePending();
        $b->reject();
        self::assertSame(BookingStatus::REJECTED, $b->status());
    }

    public function test_cancel_from_pending_or_confirmed(): void
    {
        $b1 = $this->makePending();
        $b1->cancel();
        self::assertSame(BookingStatus::CANCELLED, $b1->status());

        $b2 = $this->makePending();
        $b2->confirm();
        $b2->cancel();
        self::assertSame(BookingStatus::CANCELLED, $b2->status());

        $this->expectException(\DomainException::class);
        $b2->cancel();
    }

    public function test_mark_reminder_sent_once(): void
    {
        $b = $this->makePending();
        $b->confirm();
        self::assertNull($b->reminderSentAt());
        $b->markReminderSent(new DateTimeImmutable('2026-05-31T10:00:00', new DateTimeZone('UTC')));
        self::assertNotNull($b->reminderSentAt());

        $this->expectException(\DomainException::class);
        $b->markReminderSent(new DateTimeImmutable('2026-05-31T11:00:00', new DateTimeZone('UTC')));
    }

    public function test_confirm_is_idempotent_when_already_confirmed(): void
    {
        $b = $this->makePending();
        $b->confirm();
        $b->confirm();
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_reject_is_idempotent_when_already_rejected(): void
    {
        $b = $this->makePending();
        $b->reject();
        $b->reject();
        self::assertSame(BookingStatus::REJECTED, $b->status());
    }

    public function test_confirm_throws_from_cancelled(): void
    {
        $b = $this->makePending();
        $b->cancel();
        $this->expectException(\DomainException::class);
        $b->confirm();
    }

    public function test_reject_throws_from_cancelled(): void
    {
        $b = $this->makePending();
        $b->cancel();
        $this->expectException(\DomainException::class);
        $b->reject();
    }

    private function makePending(): Booking
    {
        return Booking::createPending(
            serviceId: 1,
            slot: $this->slot(),
            timezone: 'Europe/Paris',
            customerName: 'Jean Test',
            customerEmail: 'jean@test.fr',
            customerPhone: '0600000000',
            customerAddress: '1 rue X',
            customerMeta: [],
            notes: '',
        );
    }
}
