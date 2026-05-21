<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\BookingStatus;

final class BookingStatusTest extends TestCase
{
    public function test_blocks_slot_for_pending_and_confirmed(): void
    {
        self::assertTrue(BookingStatus::PENDING->blocksSlot());
        self::assertTrue(BookingStatus::CONFIRMED->blocksSlot());
        self::assertFalse(BookingStatus::REJECTED->blocksSlot());
        self::assertFalse(BookingStatus::CANCELLED->blocksSlot());
        self::assertFalse(BookingStatus::COMPLETED->blocksSlot());
    }

    public function test_from_string_round_trip(): void
    {
        foreach (BookingStatus::cases() as $case) {
            self::assertSame($case, BookingStatus::from($case->value));
        }
    }
}
