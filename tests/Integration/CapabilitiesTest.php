<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use Trinity\Booking\Activator;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase
{
    public function test_admin_role_has_booking_caps(): void
    {
        Activator::activate();
        $role = get_role('administrator');
        self::assertNotNull($role);
        self::assertTrue($role->has_cap('trinity_booking_manage'));
        self::assertTrue($role->has_cap('trinity_booking_view'));
    }
}
