<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\PullEventJob;
use Trinity\Booking\Google\PullResult;
use Trinity\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class PullEventJobTest extends TestCase
{
    public function test_handle_skips_if_account_missing(): void
    {
        $logged = [];
        $job = new PullEventJob(
            findAccount: fn () => null,
            buildGateway: fn () => new FakeCalendarGateway(),
            pull: fn () => new PullResult(),
            log: function (array $e) use (&$logged): void {
                $logged[] = $e;
            },
        );
        $job->handle(999);

        self::assertNotEmpty($logged);
        self::assertSame('failed', $logged[0]['status']);
        self::assertStringContainsString('account not found', (string) $logged[0]['error_message']);
    }

    public function test_handle_invokes_pull_when_account_present(): void
    {
        $utc = new DateTimeZone('UTC');
        $account = GoogleAccount::connect('l', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        $account->assignId(7);

        $invoked = false;
        $job = new PullEventJob(
            findAccount: fn (int $id) => $id === 7 ? $account : null,
            buildGateway: fn () => new FakeCalendarGateway(),
            pull: function () use (&$invoked): PullResult {
                $invoked = true;
                return new PullResult();
            },
            log: fn () => null,
        );
        $job->handle(7);
        self::assertTrue($invoked);
    }
}
