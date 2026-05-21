<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Google\OAuthState;

final class OAuthStateTest extends TestCase
{
    private OAuthState $state;

    protected function setUp(): void
    {
        $this->state = new OAuthState('test-secret-32-bytes-long-aaaaaa');
    }

    public function test_issue_then_verify(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $userId = $this->state->verify($token, now: 1_700_000_000);
        self::assertSame(1, $userId);
    }

    public function test_verify_expired_returns_null(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $userId = $this->state->verify($token, now: 1_700_000_000 + 601);
        self::assertNull($userId);
    }

    public function test_verify_tampered_returns_null(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $tampered = substr($token, 0, -2) . 'xx';
        self::assertNull($this->state->verify($tampered, now: 1_700_000_000));
    }

    public function test_verify_malformed_returns_null(): void
    {
        self::assertNull($this->state->verify('garbage', now: 1_700_000_000));
        self::assertNull($this->state->verify('a|b|c', now: 1_700_000_000));
    }
}
