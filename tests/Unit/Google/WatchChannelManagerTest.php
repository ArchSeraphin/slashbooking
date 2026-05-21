<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\WatchChannelManager;
use Slash\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class WatchChannelManagerTest extends TestCase
{
    private function freshAccount(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        $a = GoogleAccount::connect('lbl', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        $a->assignId(1);
        return $a;
    }

    public function test_start_creates_channel_and_persists_account(): void
    {
        $gateway = new FakeCalendarGateway();
        $saved   = null;
        $mgr = new WatchChannelManager(
            persist: function (GoogleAccount $a) use (&$saved): void {
                $saved = $a;
            },
            ttlSeconds: 7 * 24 * 3600,
        );

        $account = $this->freshAccount();
        $mgr->start($account, $gateway, 'https://example.test/wp-json/slashbooking/v1/google/webhook');

        self::assertNotNull($account->watchChannelId());
        self::assertNotNull($account->watchTokenSecret());
        self::assertSame('res_' . $account->watchChannelId(), $account->watchResourceId());
        self::assertNotNull($saved);

        self::assertCount(1, $gateway->startedChannels);
        self::assertSame('https://example.test/wp-json/slashbooking/v1/google/webhook', $gateway->startedChannels[0]['address']);
        self::assertSame($account->watchTokenSecret(), $gateway->startedChannels[0]['token']);
    }

    public function test_start_stops_previous_channel_first(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();
        $account->attachWatch('old_ch', 'old_res', 'old_secret', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));

        $mgr->start($account, $gateway, 'https://x.test/h');

        self::assertSame([['channelId' => 'old_ch', 'resourceId' => 'old_res']], $gateway->stoppedChannels);
        self::assertNotSame('old_ch', $account->watchChannelId());
    }

    public function test_stop_clears_state(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();
        $account->attachWatch('ch', 'res', 'sec', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));

        $mgr->stop($account, $gateway);

        self::assertNull($account->watchChannelId());
        self::assertSame([['channelId' => 'ch', 'resourceId' => 'res']], $gateway->stoppedChannels);
    }

    public function test_stop_noop_if_no_channel(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();

        $mgr->stop($account, $gateway);

        self::assertSame([], $gateway->stoppedChannels);
    }
}
