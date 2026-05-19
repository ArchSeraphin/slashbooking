<?php

declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Activator;

final class ActivatorTest extends WP_UnitTestCase
{
    public function test_activate_seeds_services_and_secret(): void
    {
        delete_option('tb_decision_secret');
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_services");

        Activator::activate();

        $secret = get_option('tb_decision_secret');
        self::assertIsString($secret);
        self::assertSame(64, strlen($secret));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $slugs = $wpdb->get_col("SELECT slug FROM {$wpdb->prefix}tb_services ORDER BY sort_order");
        self::assertSame(['pv', 'irve'], $slugs);
    }

    public function test_activate_is_idempotent(): void
    {
        $first = get_option('tb_decision_secret');
        Activator::activate();
        $second = get_option('tb_decision_secret');
        self::assertSame($first, $second);

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tb_services");
        self::assertSame(2, $count);
    }
}
