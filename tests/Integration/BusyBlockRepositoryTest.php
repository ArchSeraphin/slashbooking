<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Persistence\BusyBlockRepository;

final class BusyBlockRepositoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            self::markTestSkipped('WP test suite not available.');
        }
    }

    protected function setUp(): void
    {
        if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \wpdb)) {
            $this->markTestSkipped('Requires wp-phpunit (run via composer test:integration).');
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sb_busy_blocks");
    }

    public function test_upsert_inserts_new_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $bb = BusyBlock::fromGoogleEvent(
            googleAccountId: 1,
            eventId: 'gcal_a',
            start: new DateTimeImmutable('2026-06-01 09:00:00', new DateTimeZone('UTC')),
            end:   new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')),
            summary: 'Demo',
        );
        $repo->upsertFromGoogle($bb);

        $found = $repo->findBySourceId(1, 'gcal_a');
        self::assertNotNull($found);
        self::assertSame('Demo', $found->summary);
    }

    public function test_upsert_updates_existing_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $start = new DateTimeImmutable('2026-06-02 09:00:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-02 10:00:00', new DateTimeZone('UTC'));

        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(1, 'gcal_b', $start, $end, 'v1'));
        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(1, 'gcal_b', $start, $end, 'v2'));

        $found = $repo->findBySourceId(1, 'gcal_b');
        self::assertNotNull($found);
        self::assertSame('v2', $found->summary);
    }

    public function test_delete_by_source_id_removes_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(
            1,
            'gcal_c',
            new DateTimeImmutable('2026-06-03 09:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-03 10:00:00', new DateTimeZone('UTC')),
            'X',
        ));
        $repo->deleteBySourceId(1, 'gcal_c');

        self::assertNull($repo->findBySourceId(1, 'gcal_c'));
    }

    public function test_delete_no_op_if_missing(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);
        $repo->deleteBySourceId(1, 'gcal_nonexistent');
        self::assertTrue(true);
    }
}
