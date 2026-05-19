<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Persistence\Migrator;
use Trinity\Booking\Plugin;

final class MigratorTest extends WP_UnitTestCase
{
    public function test_creates_all_tables(): void
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $migrator->migrate();

        $expected = [
            $wpdb->prefix . 'tb_services',
            $wpdb->prefix . 'tb_bookings',
            $wpdb->prefix . 'tb_busy_blocks',
            $wpdb->prefix . 'tb_google_accounts',
            $wpdb->prefix . 'tb_sync_log',
            $wpdb->prefix . 'tb_mail_templates',
        ];

        foreach ($expected as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            self::assertSame($table, $result, "Missing table: {$table}");
        }
    }

    public function test_idempotent(): void
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $migrator->migrate();
        $migrator->migrate(); // doit pas planter
        self::assertSame(Plugin::DB_VERSION, (int) get_option('tb_db_version'));
    }
}
