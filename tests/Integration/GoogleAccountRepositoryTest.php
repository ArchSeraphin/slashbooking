<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class GoogleAccountRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \wpdb)) {
            $this->markTestSkipped('Requires wp-phpunit (run via composer test:integration).');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_google_accounts");
    }

    public function test_save_then_find_single_returns_account(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('Commercial', 'primary', 'enc-refresh', 'enc-access', $now->modify('+1 hour'));

        $repo->save($acct);
        self::assertNotNull($acct->id());

        $found = $repo->findSingle();
        self::assertNotNull($found);
        self::assertSame('primary', $found->calendarId());
        self::assertSame('enc-refresh', $found->refreshTokenEnc());
    }

    public function test_save_updates_existing(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r1', 'a1', $now->modify('+1 hour'));
        $repo->save($acct);

        $acct->rotateAccessToken('a2', $now->modify('+2 hours'));
        $repo->save($acct);

        $found = $repo->findSingle();
        self::assertSame('a2', $found?->accessTokenEnc());
    }

    public function test_delete_removes(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now);
        $repo->save($acct);
        $repo->delete((int) $acct->id());
        self::assertNull($repo->findSingle());
    }
}
