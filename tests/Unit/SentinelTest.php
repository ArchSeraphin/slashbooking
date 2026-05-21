<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SentinelTest extends TestCase
{
    public function test_runner_is_alive(): void
    {
        self::assertTrue(true);
    }
}
