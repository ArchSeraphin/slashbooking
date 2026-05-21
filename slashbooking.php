<?php
/**
 * Plugin Name:       SlashBooking
 * Plugin URI:        https://slashbooking.example/
 * Description:       Online appointment booking with Google Calendar sync.
 * Version:           1.0.13
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            SlashBooking
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slashbooking
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    return;
}
require_once $autoload;

// Action Scheduler bootstrap — must run before plugins_loaded so other plugins can enqueue.
$sb_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (is_readable($sb_action_scheduler)) {
    require_once $sb_action_scheduler;
}
unset($sb_action_scheduler);

register_activation_hook(__FILE__, [\Slash\Booking\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Slash\Booking\Deactivator::class, 'deactivate']);

\Slash\Booking\Plugin::boot(__FILE__);
