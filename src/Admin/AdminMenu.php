<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            page_title: __('SlashBooking', 'slashbooking'),
            menu_title: __('SlashBooking', 'slashbooking'),
            capability: Capabilities::VIEW,
            menu_slug:  'slashbooking',
            callback:   [$this, 'render'],
            icon_url:   'dashicons-calendar-alt',
            position:   25,
        );
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('SlashBooking', 'slashbooking') . '</h1>';
        echo '<div id="sb-admin-app"></div></div>';
    }
}
