<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Google\SyncLogPurger;

final class SyncLogPurgerTest extends TestCase
{
    public function test_purge_called_with_30_day_cutoff(): void
    {
        $captured = null;
        $purger = new SyncLogPurger(
            purge: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 7;
            }
        );

        $now = new DateTimeImmutable('2026-06-01T12:00:00Z');
        $deleted = $purger->run($now);

        self::assertSame(7, $deleted);
        self::assertSame('2026-05-02T12:00:00+00:00', $captured?->format(\DateTimeInterface::ATOM));
    }
}
