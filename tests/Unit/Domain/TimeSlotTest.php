<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class TimeSlotTest extends TestCase
{
    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function test_constructs_valid_slot(): void
    {
        $slot = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        self::assertSame(90, $slot->durationMinutes());
    }

    public function test_rejects_inverted_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TimeSlot($this->utc('2026-06-01T09:30:00Z'), $this->utc('2026-06-01T08:00:00Z'));
    }

    public function test_rejects_non_utc_dates(): void
    {
        $start = new DateTimeImmutable('2026-06-01T08:00:00', new DateTimeZone('Europe/Paris'));
        $end   = new DateTimeImmutable('2026-06-01T09:30:00', new DateTimeZone('Europe/Paris'));
        $this->expectException(\InvalidArgumentException::class);
        new TimeSlot($start, $end);
    }

    public function test_overlaps_detection(): void
    {
        $a = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:00:00Z'));
        $b = new TimeSlot($this->utc('2026-06-01T08:30:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $c = new TimeSlot($this->utc('2026-06-01T09:00:00Z'), $this->utc('2026-06-01T10:00:00Z'));
        self::assertTrue($a->overlaps($b));
        self::assertFalse($a->overlaps($c)); // tangent, exclusif à droite
    }

    public function test_expand_returns_new_slot(): void
    {
        $slot = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $expanded = $slot->expand(30, 30);
        self::assertSame('2026-06-01T07:30:00+00:00', $expanded->start->format('c'));
        self::assertSame('2026-06-01T10:00:00+00:00', $expanded->end->format('c'));
    }
}
