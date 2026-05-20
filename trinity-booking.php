<?php
/**
 * Plugin Name:       Trinity Booking
 * Plugin URI:        https://trinity.example/
 * Description:       Online appointment booking with Google Calendar sync.
 * Version:           0.1.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Trinity
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trinity-booking
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
$tb_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (is_readable($tb_action_scheduler)) {
    require_once $tb_action_scheduler;
}
unset($tb_action_scheduler);

register_activation_hook(__FILE__, [\Trinity\Booking\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Trinity\Booking\Deactivator::class, 'deactivate']);

\Trinity\Booking\Plugin::boot(__FILE__);
