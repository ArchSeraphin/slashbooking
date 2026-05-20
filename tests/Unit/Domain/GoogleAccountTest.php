<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\GoogleAccount;

final class GoogleAccountTest extends TestCase
{
    public function test_connect_sets_tokens_and_expiry(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect(
            label: 'Commercial Trinity',
            calendarId: 'primary',
            refreshTokenEnc: 'enc-refresh',
            accessTokenEnc: 'enc-access',
            expiresAt: $now->modify('+3600 seconds'),
        );

        self::assertSame('primary', $acct->calendarId());
        self::assertSame('enc-refresh', $acct->refreshTokenEnc());
        self::assertSame('enc-access', $acct->accessTokenEnc());
        self::assertFalse($acct->accessTokenExpired($now));
    }

    public function test_access_token_expired_after_expiry(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now->modify('-1 second'));
        self::assertTrue($acct->accessTokenExpired($now));
    }

    public function test_rotate_access_token(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now);
        $acct->rotateAccessToken('enc-new', $now->modify('+3600 seconds'));
        self::assertSame('enc-new', $acct->accessTokenEnc());
        self::assertFalse($acct->accessTokenExpired($now->modify('+10 seconds')));
    }
}
