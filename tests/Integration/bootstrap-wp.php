<?php
declare(strict_types=1);

$wp_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (!is_dir($wp_tests_dir) || !is_file($wp_tests_dir . '/includes/functions.php')) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    fwrite(STDERR, "\033[33mWP test suite not found at {$wp_tests_dir}. Skipping integration tests.\033[0m\n");
    exit(0);
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/slashbooking.php';
});

require $wp_tests_dir . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
