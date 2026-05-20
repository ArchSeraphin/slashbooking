<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use Trinity\Booking\Activator;
use Trinity\Booking\Admin\AdminMenu;
use WP_UnitTestCase;

final class AdminMenuTest extends WP_UnitTestCase
{
    public function test_admin_can_see_menu(): void
    {
        Activator::activate();
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        set_current_screen('dashboard');

        (new AdminMenu())->register();
        do_action('admin_menu');

        global $menu;
        $slugs = array_column($menu ?? [], 2);
        self::assertContains('trinity-booking', $slugs);
    }
}
