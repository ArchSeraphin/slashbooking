<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Availability;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Availability\SlotGenerator;
use Slash\Booking\Domain\Service;
use DateTimeImmutable;
use DateTimeZone;

final class SlotGeneratorTest extends TestCase
{
    private function service(int $duration = 90): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'PV',
            durationMin: $duration,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 30,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '12:00']],
            ],
            active: true, color: '#000',
        );
    }

    public function test_generates_aligned_slots_for_one_day(): void
    {
        $svc = $this->service(90);
        $gen = new SlotGenerator(stepMinutes: 15, siteTimezone: 'Europe/Paris');
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-01', new DateTimeZone('Europe/Paris')),
            to:   new DateTimeImmutable('2026-06-02', new DateTimeZone('Europe/Paris')),
        );

        // 1 juin 2026 = lundi. Horaires 9-12, durée 90 min, pas 15.
        // Slots possibles : 09:00, 09:15, 09:30, … jusqu'au dernier qui finit ≤ 12:00.
        // 09:00→10:30, 09:15→10:45, …, 10:30→12:00. = 7 slots.
        self::assertCount(7, $slots);
        self::assertSame('2026-06-01T07:00:00+00:00', $slots[0]->start->format('c')); // 09:00 Paris en juin = UTC+2
        self::assertSame('2026-06-01T08:30:00+00:00', $slots[0]->end->format('c'));
    }

    public function test_generates_nothing_for_closed_day(): void
    {
        $svc = $this->service(90);
        $gen = new SlotGenerator(stepMinutes: 15, siteTimezone: 'Europe/Paris');
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-07', new DateTimeZone('Europe/Paris')), // dimanche
            to:   new DateTimeImmutable('2026-06-08', new DateTimeZone('Europe/Paris')),
        );
        self::assertSame([], $slots);
    }

    public function test_min_lead_time_filters_too_close_slots(): void
    {
        $svc = new Service(
            id: 1, slug: 'pv', name: 'PV',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 48, maxHorizonDays: 30,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '12:00']],
            ],
            active: true, color: '#000',
        );
        $gen = new SlotGenerator(
            stepMinutes: 15,
            siteTimezone: 'Europe/Paris',
            now: new DateTimeImmutable('2026-05-31T10:00:00', new DateTimeZone('UTC')),
        );
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-01', new DateTimeZone('Europe/Paris')),
            to:   new DateTimeImmutable('2026-06-02', new DateTimeZone('Europe/Paris')),
        );
        // 31 mai 10:00 UTC + 48h = 2 juin 10:00 UTC, donc tous les slots du 1er juin sont filtrés.
        self::assertSame([], $slots);
    }
}
