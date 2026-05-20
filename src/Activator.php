<?php

declare(strict_types=1);

namespace Trinity\Booking;

use Trinity\Booking\Persistence\Migrator;

final class Activator
{
    public static function activate(): void
    {
        global $wpdb;
        (new Migrator($wpdb))->migrate();

        self::ensureDecisionSecret();
        self::seedServices($wpdb);
        Admin\Capabilities::install();

        if (!wp_next_scheduled(\Trinity\Booking\Notifications\ReminderScheduler::HOOK)) {
            wp_schedule_event(self::tomorrowAt10SiteTz(), 'daily', \Trinity\Booking\Notifications\ReminderScheduler::HOOK);
        }

        if (!wp_next_scheduled(\Trinity\Booking\Google\SyncLogPurger::HOOK)) {
            wp_schedule_event(self::tomorrowAt3SiteTz(), 'daily', \Trinity\Booking\Google\SyncLogPurger::HOOK);
        }

        if (!wp_next_scheduled('tb/watch_renew_check')) {
            wp_schedule_event(self::tomorrowAt4SiteTz(), 'daily', 'tb/watch_renew_check');
        }

        // 15-minute cron interval: filter must be registered before scheduling.
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['tb_fifteen_minutes'])) {
                $s['tb_fifteen_minutes'] = [
                    'interval' => 900,
                    'display'  => 'Every 15 minutes (Trinity Booking)',
                ];
            }
            return $s;
        });
        if (!wp_next_scheduled('tb/google_pull_all')) {
            wp_schedule_event(time() + 900, 'tb_fifteen_minutes', 'tb/google_pull_all');
        }
    }

    private static function tomorrowAt10SiteTz(): int
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $when = new \DateTimeImmutable('tomorrow 10:00', $tz);
        return $when->getTimestamp();
    }

    private static function tomorrowAt3SiteTz(): int
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        return (new \DateTimeImmutable('tomorrow 03:00', $tz))->getTimestamp();
    }

    private static function tomorrowAt4SiteTz(): int
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        return (new \DateTimeImmutable('tomorrow 04:00', $tz))->getTimestamp();
    }

    private static function ensureDecisionSecret(): void
    {
        $existing = get_option('tb_decision_secret');
        if (!is_string($existing) || strlen($existing) !== 64) {
            update_option('tb_decision_secret', bin2hex(random_bytes(32)), false);
        }
    }

    /**
     * @param \wpdb $wpdb
     */
    private static function seedServices(\wpdb $wpdb): void
    {
        $defaults = [
            [
                'slug'         => 'pv',
                'name'         => 'Photovoltaïque',
                'duration_min' => 90,
                'sort_order'   => 1,
                'color'        => '#f59e0b',
            ],
            [
                'slug'         => 'irve',
                'name'         => 'Borne de recharge',
                'duration_min' => 45,
                'sort_order'   => 2,
                'color'        => '#10b981',
            ],
        ];

        $weeklyHours = [
            1 => [['open' => '09:00', 'close' => '18:00']],
            2 => [['open' => '09:00', 'close' => '18:00']],
            3 => [['open' => '09:00', 'close' => '18:00']],
            4 => [['open' => '09:00', 'close' => '18:00']],
            5 => [['open' => '09:00', 'close' => '18:00']],
            6 => [['open' => '09:00', 'close' => '13:00']],
        ];

        $now = current_time('mysql', true);

        $table = $wpdb->prefix . 'tb_services';

        foreach ($defaults as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare('SELECT id FROM ' . $table . ' WHERE slug = %s', $row['slug'])
            );
            if ($exists) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                "{$wpdb->prefix}tb_services",
                [
                    'slug'              => $row['slug'],
                    'name'              => $row['name'],
                    'duration_min'      => $row['duration_min'],
                    'buffer_before_min' => 0,
                    'buffer_after_min'  => 30,
                    'min_lead_time_hours' => 24,
                    'max_horizon_days'  => 60,
                    'color'             => $row['color'],
                    'active'            => 1,
                    'sort_order'        => $row['sort_order'],
                    'settings'          => wp_json_encode(['weekly_hours' => $weeklyHours]),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
                ['%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s']
            );
        }
    }
}
