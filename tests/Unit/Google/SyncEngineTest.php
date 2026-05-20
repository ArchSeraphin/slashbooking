<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\BusyBlock;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\SyncEngine;
use Trinity\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class SyncEngineTest extends TestCase
{
    private function account(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        $a = GoogleAccount::connect('l', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        $a->assignId(1);
        return $a;
    }

    public function test_full_sync_upserts_external_events_only(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [
                [
                    'id'      => 'ev_external',
                    'status'  => 'confirmed',
                    'summary' => 'Atelier',
                    'start'   => '2026-06-01T09:00:00+02:00',
                    'end'     => '2026-06-01T10:00:00+02:00',
                    'updated' => '2026-05-20T10:00:00Z',
                    'etag'    => '"e1"',
                ],
                [
                    'id'      => 'ev_ours',
                    'status'  => 'confirmed',
                    'summary' => 'Booking via plugin',
                    'start'   => '2026-06-02T14:00:00+02:00',
                    'end'     => '2026-06-02T15:30:00+02:00',
                    'updated' => '2026-05-20T11:00:00Z',
                    'etag'    => '"e2"',
                ],
            ],
            nextSyncToken: 'tok_v2',
        );

        $upserts = [];
        $deletes = [];
        $logged  = [];

        $engine = new SyncEngine(
            findBookingByEventId: fn (string $id) => $id === 'ev_ours' ? 42 : null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb;
            },
            deleteBusyBlock: function (int $accountId, string $sourceId) use (&$deletes): void {
                $deletes[] = [$accountId, $sourceId];
            },
            persistAccount: fn () => null,
            log: function (array $entry) use (&$logged): void {
                $logged[] = $entry;
            },
        );

        $account = $this->account();
        $result = $engine->pull($account, $gateway);

        self::assertCount(1, $upserts);
        self::assertSame('ev_external', $upserts[0]->sourceId);
        self::assertSame([], $deletes);
        self::assertSame('tok_v2', $account->syncToken());
        self::assertSame(1, $result->upserted);
        self::assertSame(0, $result->deleted);
        self::assertSame(1, $result->ignoredReflection);
        self::assertNotEmpty($logged);
    }

    public function test_cancelled_event_deletes_busy_block(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [[
                'id'      => 'ev_x',
                'status'  => 'cancelled',
                'summary' => '',
                'start'   => null,
                'end'     => null,
                'updated' => '2026-05-20T10:00:00Z',
                'etag'    => '"e"',
            ]],
            nextSyncToken: 'tok_z',
        );

        $deletes = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: fn () => null,
            deleteBusyBlock: function (int $a, string $id) use (&$deletes): void {
                $deletes[] = [$a, $id];
            },
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $result = $engine->pull($account, $gateway);

        self::assertSame([[1, 'ev_x']], $deletes);
        self::assertSame(1, $result->deleted);
    }

    public function test_paginates_until_no_next_page_token(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [[
                'id' => 'p1', 'status' => 'confirmed', 'summary' => 'a',
                'start' => '2026-06-01T09:00:00Z', 'end' => '2026-06-01T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextPageToken: 'page_2',
        );
        $gateway->queuePage(
            items: [[
                'id' => 'p2', 'status' => 'confirmed', 'summary' => 'b',
                'start' => '2026-06-02T09:00:00Z', 'end' => '2026-06-02T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextSyncToken: 'final_tok',
        );

        $upserts = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb->sourceId;
            },
            deleteBusyBlock: fn () => null,
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $engine->pull($account, $gateway);

        self::assertSame(['p1', 'p2'], $upserts);
        self::assertSame('final_tok', $account->syncToken());
    }

    public function test_410_gone_resets_sync_token_and_retries_full_sync(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->throwSyncTokenExpiredNext = true;
        $gateway->queuePage(
            items: [[
                'id' => 'ev_fresh', 'status' => 'confirmed', 'summary' => 'x',
                'start' => '2026-06-01T09:00:00Z', 'end' => '2026-06-01T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextSyncToken: 'tok_new',
        );

        $upserts = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb->sourceId;
            },
            deleteBusyBlock: fn () => null,
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $account->updateSyncToken('stale_token');
        $engine->pull($account, $gateway);

        self::assertSame('tok_new', $account->syncToken());
        self::assertSame(['ev_fresh'], $upserts);
    }
}
