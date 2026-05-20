<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\BusyBlock;

final class BusyBlockTest extends TestCase
{
    public function test_from_google_event_builds_block_with_utc_slot(): void
    {
        $bb = BusyBlock::fromGoogleEvent(
            googleAccountId: 7,
            eventId: 'gcal_abc',
            start: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
            end: new DateTimeImmutable('2026-06-01T10:00:00+02:00'),
            summary: 'Atelier interne',
            syncedAt: new DateTimeImmutable('2026-05-20T08:00:00Z'),
        );

        self::assertNull($bb->id);
        self::assertSame('google', $bb->source);
        self::assertSame('gcal_abc', $bb->sourceId);
        self::assertSame(7, $bb->googleAccountId);
        self::assertSame('Atelier interne', $bb->summary);
        self::assertSame('UTC', $bb->slot->start->getTimezone()->getName());
        self::assertSame('2026-06-01 07:00:00', $bb->slot->start->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01 08:00:00', $bb->slot->end->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-20 08:00:00', $bb->lastSyncedAt->format('Y-m-d H:i:s'));
    }

    public function test_from_google_event_defaults_synced_at_to_now(): void
    {
        $bb = BusyBlock::fromGoogleEvent(
            googleAccountId: 1,
            eventId: 'x',
            start: new DateTimeImmutable('2026-06-01T09:00:00Z'),
            end: new DateTimeImmutable('2026-06-01T10:00:00Z'),
            summary: 's',
        );
        self::assertNotNull($bb->lastSyncedAt);
        self::assertSame('UTC', $bb->lastSyncedAt->getTimezone()->getName());
    }
}
