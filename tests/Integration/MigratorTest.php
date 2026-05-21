<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use WP_UnitTestCase;
use Slash\Booking\Persistence\Migrator;
use Slash\Booking\Plugin;

final class MigratorTest extends WP_UnitTestCase
{
    public function test_creates_all_tables(): void
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $migrator->migrate();

        $expected = [
            $wpdb->prefix . 'sb_services',
            $wpdb->prefix . 'sb_bookings',
            $wpdb->prefix . 'sb_busy_blocks',
            $wpdb->prefix . 'sb_google_accounts',
            $wpdb->prefix . 'sb_sync_log',
            $wpdb->prefix . 'sb_mail_templates',
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
        self::assertSame(Plugin::DB_VERSION, (int) get_option('sb_db_version'));
    }
}
