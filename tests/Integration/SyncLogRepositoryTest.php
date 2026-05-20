<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Persistence\SyncLogRepository;

final class SyncLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \wpdb)) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");
    }

    public function test_append_then_paginate(): void
    {
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);

        for ($i = 1; $i <= 3; $i++) {
            $repo->append(
                level: 'info',
                direction: 'wp_to_g',
                entity: 'booking',
                entityId: $i,
                googleEventId: 'evt_' . $i,
                action: 'create',
                status: 'ok',
                payload: ['n' => $i],
                errorMessage: null,
            );
        }

        $page = $repo->paginate([], 1, 10);
        self::assertSame(3, $page['total']);
        self::assertCount(3, $page['items']);
        self::assertSame('evt_3', $page['items'][0]['google_event_id']);
    }

    public function test_purge_older_than(): void
    {
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);
        $repo->append('info', 'wp_to_g', 'booking', 1, null, 'create', 'ok', [], null);
        $wpdb->query("UPDATE {$wpdb->prefix}tb_sync_log SET ts = DATE_SUB(NOW(), INTERVAL 40 DAY)");

        $repo->append('info', 'wp_to_g', 'booking', 2, null, 'create', 'ok', [], null);

        $deleted = $repo->purgeOlderThan(new DateTimeImmutable('-30 days', new DateTimeZone('UTC')));
        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->paginate([], 1, 10)['total']);
    }
}
