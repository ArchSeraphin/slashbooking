<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountTest extends TestCase
{
    private function fresh(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        return GoogleAccount::connect(
            label: 'Commercial',
            calendarId: 'primary',
            refreshTokenEnc: 'refresh',
            accessTokenEnc: 'access',
            expiresAt: new DateTimeImmutable('+1 hour', $utc),
        );
    }

    public function test_attach_watch_sets_all_fields(): void
    {
        $a = $this->fresh();
        $a->attachWatch(
            channelId: 'ch_1',
            resourceId: 'res_1',
            tokenSecret: 'secret_xyz',
            expiresAt: new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame('ch_1', $a->watchChannelId());
        self::assertSame('res_1', $a->watchResourceId());
        self::assertSame('secret_xyz', $a->watchTokenSecret());
        self::assertSame('2026-06-01 10:00:00', $a->watchExpiresAt()?->format('Y-m-d H:i:s'));
    }

    public function test_clear_watch_resets_fields(): void
    {
        $a = $this->fresh();
        $a->attachWatch('ch', 'res', 'tok', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        $a->clearWatch();

        self::assertNull($a->watchChannelId());
        self::assertNull($a->watchResourceId());
        self::assertNull($a->watchTokenSecret());
        self::assertNull($a->watchExpiresAt());
    }

    public function test_verify_watch_token_uses_hash_equals(): void
    {
        $a = $this->fresh();
        self::assertFalse($a->verifyWatchToken('anything')); // no watch yet
        $a->attachWatch('ch', 'res', 'correct_secret', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        self::assertTrue($a->verifyWatchToken('correct_secret'));
        self::assertFalse($a->verifyWatchToken('wrong'));
        self::assertFalse($a->verifyWatchToken(''));
    }

    public function test_update_sync_token(): void
    {
        $a = $this->fresh();
        self::assertNull($a->syncToken());
        $a->updateSyncToken('CAESDQjs');
        self::assertSame('CAESDQjs', $a->syncToken());
    }

    public function test_clear_sync_token(): void
    {
        $a = $this->fresh();
        $a->updateSyncToken('something');
        $a->clearSyncToken();
        self::assertNull($a->syncToken());
    }

    public function test_mark_full_synced_at(): void
    {
        $a = $this->fresh();
        $when = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));
        $a->markFullSyncedAt($when);
        self::assertSame('2026-05-20 12:00:00', $a->lastFullSyncAt()?->format('Y-m-d H:i:s'));
    }

    public function test_set_calendar_id_clears_watch_and_sync_token(): void
    {
        $a = $this->fresh();
        $a->attachWatch('ch', 'res', 'tok', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        $a->updateSyncToken('sync_abc');

        $a->setCalendarId('work@example.com');

        self::assertSame('work@example.com', $a->calendarId());
        self::assertNull($a->watchChannelId());
        self::assertNull($a->syncToken());
    }

    public function test_set_calendar_id_noop_when_unchanged(): void
    {
        $a = $this->fresh(); // calendar = 'primary'
        $a->attachWatch('ch', 'res', 'tok', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        $a->updateSyncToken('sync_abc');

        $a->setCalendarId('primary'); // same → no side-effects

        self::assertSame('ch', $a->watchChannelId());
        self::assertSame('sync_abc', $a->syncToken());
    }

    public function test_set_calendar_id_rejects_empty(): void
    {
        $a = $this->fresh();
        $this->expectException(\DomainException::class);
        $a->setCalendarId('   ');
    }
}
