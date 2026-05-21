<?php

declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\CreateBooking;
use Slash\Booking\Booking\Exceptions\InvalidBookingInput;
use Slash\Booking\Booking\Exceptions\SlotUnavailable;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;

final class CreateBookingTest extends TestCase
{
    private function service(): Service
    {
        return new Service(
            id: 1,
            slug: 'pv',
            name: 'PV',
            durationMin: 90,
            bufferBeforeMin: 0,
            bufferAfterMin: 30,
            minLeadTimeHours: 0,
            maxHorizonDays: 60,
            weeklyHours: [1 => [['open' => '09:00', 'close' => '18:00']]],
            active: true,
            color: '#000',
        );
    }

    private function slot(): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
    }

    public function test_happy_path_saves_pending_booking(): void
    {
        $saved = null;
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: function (Booking $b) use (&$saved): void {
                $saved = $b;
                $b->assignId(123);
            },
        );
        $created = $useCase->execute([
            'service' => $this->service(),
            'slot' => $this->slot(),
            'timezone' => 'Europe/Paris',
            'customer_name' => 'Jean Test',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'customer_meta' => [],
            'notes' => '',
            'consent' => true,
        ]);
        self::assertSame(123, $created->id());
        self::assertNotNull($saved);
    }

    public function test_rejects_when_slot_unavailable(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => false,
            persist: fn (Booking $b) => null,
        );
        $this->expectException(SlotUnavailable::class);
        $useCase->execute([
            'service' => $this->service(),
            'slot' => $this->slot(),
            'timezone' => 'Europe/Paris',
            'customer_name' => 'Jean',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'customer_meta' => [],
            'notes' => '',
            'consent' => true,
        ]);
    }

    public function test_rejects_when_consent_missing(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: fn (Booking $b) => null,
        );
        try {
            $useCase->execute([
                'service' => $this->service(),
                'slot' => $this->slot(),
                'timezone' => 'Europe/Paris',
                'customer_name' => 'Jean',
                'customer_email' => 'jean@test.fr',
                'customer_phone' => '0600000000',
                'customer_address' => '1 rue X',
                'customer_meta' => [],
                'notes' => '',
                'consent' => false,
            ]);
            self::fail('Expected InvalidBookingInput');
        } catch (InvalidBookingInput $e) {
            self::assertArrayHasKey('consent', $e->errors);
        }
    }

    public function test_rejects_invalid_email(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: fn (Booking $b) => null,
        );
        try {
            $useCase->execute([
                'service' => $this->service(),
                'slot' => $this->slot(),
                'timezone' => 'Europe/Paris',
                'customer_name' => 'Jean',
                'customer_email' => 'not-an-email',
                'customer_phone' => '0600000000',
                'customer_address' => '1 rue X',
                'customer_meta' => [],
                'notes' => '',
                'consent' => true,
            ]);
            self::fail('Expected InvalidBookingInput');
        } catch (InvalidBookingInput $e) {
            self::assertArrayHasKey('customer_email', $e->errors);
        }
    }
}
