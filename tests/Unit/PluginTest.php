<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Plugin;

final class PluginTest extends TestCase
{
    public function test_version_returns_non_empty_string(): void
    {
        self::assertNotEmpty(Plugin::version());
    }

    public function test_text_domain_constant(): void
    {
        self::assertSame('trinity-booking', Plugin::TEXT_DOMAIN);
    }
}
