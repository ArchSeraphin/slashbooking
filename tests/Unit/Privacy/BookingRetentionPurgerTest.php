<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Privacy\BookingRetentionPurger;

final class BookingRetentionPurgerTest extends TestCase
{
    public function test_deletes_with_correct_cutoff(): void
    {
        $captured = null;
        $purger = new BookingRetentionPurger(
            retentionDays: 1095,
            deleteOlderThan: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 7;
            },
            now: new DateTimeImmutable('2026-05-20 03:30:00'),
        );

        $count = $purger->purge();

        $this->assertSame(7, $count);
        $this->assertSame('2023-05-21 03:30:00', $captured->format('Y-m-d H:i:s'));
    }

    public function test_uses_custom_retention(): void
    {
        $captured = null;
        $purger = new BookingRetentionPurger(
            retentionDays: 365,
            deleteOlderThan: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 0;
            },
            now: new DateTimeImmutable('2026-05-20 00:00:00'),
        );

        $purger->purge();

        $this->assertSame('2025-05-20 00:00:00', $captured->format('Y-m-d H:i:s'));
    }
}
