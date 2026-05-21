<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\PublicFront;

use PHPUnit\Framework\TestCase;
use Slash\Booking\PublicFront\TurnstileVerifier;

final class TurnstileVerifierTest extends TestCase
{
    public function test_fail_open_when_no_secret_configured(): void
    {
        $v = new TurnstileVerifier('');
        self::assertTrue($v->verify('anything'));
        self::assertTrue($v->verify(''));
        self::assertFalse($v->isConfigured());
    }

    public function test_empty_token_rejected_when_configured(): void
    {
        $v = new TurnstileVerifier('secret', fn (): array => ['success' => true]);
        self::assertFalse($v->verify(''));
        self::assertFalse($v->verify('   '));
    }

    public function test_success_response_returns_true(): void
    {
        $stub = function (string $url, array $body): array {
            self::assertSame(TurnstileVerifier::ENDPOINT, $url);
            self::assertSame('the-secret', $body['secret']);
            self::assertSame('valid-token', $body['response']);
            self::assertSame('1.2.3.4', $body['remoteip']);
            return ['success' => true, 'hostname' => 'example.test'];
        };
        $v = new TurnstileVerifier('the-secret', $stub);
        self::assertTrue($v->verify('valid-token', '1.2.3.4'));
    }

    public function test_unsuccessful_response_returns_false(): void
    {
        $v = new TurnstileVerifier('s', fn (): array => ['success' => false, 'error-codes' => ['invalid-input-response']]);
        self::assertFalse($v->verify('bad-token'));
    }

    public function test_null_response_returns_false(): void
    {
        $v = new TurnstileVerifier('s', fn (): ?array => null);
        self::assertFalse($v->verify('whatever'));
    }

    public function test_ip_omitted_when_not_provided(): void
    {
        $captured = [];
        $stub = function (string $url, array $body) use (&$captured): array {
            $captured = $body;
            return ['success' => true];
        };
        $v = new TurnstileVerifier('s', $stub);
        $v->verify('t');
        self::assertArrayNotHasKey('remoteip', $captured);
    }
}
