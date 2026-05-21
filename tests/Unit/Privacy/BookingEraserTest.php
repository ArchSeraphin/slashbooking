<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Privacy\BookingEraser;

final class BookingEraserTest extends TestCase
{
    public function test_anonymizes_matching_bookings(): void
    {
        $calls = [];
        $eraser = new BookingEraser(
            anonymizeByEmail: function (string $email) use (&$calls): int {
                $calls[] = $email;
                return 3;
            },
        );

        $result = $eraser->erase('alice@example.com', 1);

        $this->assertSame(['alice@example.com'], $calls);
        $this->assertSame(3, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['messages']);
    }

    public function test_returns_zero_when_no_match(): void
    {
        $eraser = new BookingEraser(anonymizeByEmail: fn () => 0);
        $result = $eraser->erase('unknown@example.com', 1);

        $this->assertSame(0, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertSame([], $result['messages']);
    }
}
