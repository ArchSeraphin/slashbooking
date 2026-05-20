<?php
declare(strict_types=1);

namespace Trinity\Booking\Admin;

final class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            page_title: __('Trinity Booking', 'trinity-booking'),
            menu_title: __('Trinity Booking', 'trinity-booking'),
            capability: Capabilities::VIEW,
            menu_slug:  'trinity-booking',
            callback:   [$this, 'render'],
            icon_url:   'dashicons-calendar-alt',
            position:   25,
        );
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Trinity Booking', 'trinity-booking') . '</h1>';
        echo '<div id="tb-admin-app"></div></div>';
    }
}
