<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Availability;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Availability\AvailabilityCalculator;
use Slash\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class AvailabilityCalculatorTest extends TestCase
{
    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function test_filters_slots_overlapping_busy_with_buffer(): void
    {
        $candidate1 = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $candidate2 = new TimeSlot($this->utc('2026-06-01T09:30:00Z'), $this->utc('2026-06-01T11:00:00Z'));
        $candidate3 = new TimeSlot($this->utc('2026-06-01T11:00:00Z'), $this->utc('2026-06-01T12:30:00Z'));

        $busy = [new TimeSlot($this->utc('2026-06-01T09:45:00Z'), $this->utc('2026-06-01T10:30:00Z'))];

        $calc = new AvailabilityCalculator(bufferBeforeMin: 30, bufferAfterMin: 30);
        $free = $calc->filter([$candidate1, $candidate2, $candidate3], $busy);

        // Candidate1 (08:00-09:30) avec buffer = 07:30-10:00 → chevauche busy 09:45-10:30 → exclu
        // Candidate2 (09:30-11:00) avec buffer = 09:00-11:30 → chevauche → exclu
        // Candidate3 (11:00-12:30) avec buffer = 10:30-13:00 → tangent à 10:30 (overlaps strict → ok pour candidate3)
        self::assertCount(1, $free);
        self::assertSame('2026-06-01T11:00:00+00:00', $free[0]->start->format('c'));
    }

    public function test_no_busy_returns_all(): void
    {
        $candidate = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $calc = new AvailabilityCalculator(0, 0);
        self::assertSame([$candidate], $calc->filter([$candidate], []));
    }
}
