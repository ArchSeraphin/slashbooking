<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use Slash\Booking\Activator;
use Slash\Booking\Admin\AdminMenu;
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
        self::assertContains('slashbooking', $slugs);
    }
}
