<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use Trinity\Booking\Persistence\SyncLogRepository;

final class AdminSyncLogControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");

        wp_set_current_user(1);
        wp_get_current_user()->add_cap('trinity_booking_manage');
    }

    public function test_list_returns_paginated_log(): void
    {
        do_action('rest_api_init');
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);
        for ($i = 1; $i <= 4; $i++) {
            $repo->append('info', 'wp_to_g', 'booking', $i, 'evt_' . $i, 'create', 'ok', [], null);
        }

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/sync-log');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $req->set_query_params(['per_page' => 2, 'page' => 1]);
        $res = rest_do_request($req);
        $data = $res->get_data();

        self::assertSame(4, $data['total']);
        self::assertCount(2, $data['items']);
    }
}
