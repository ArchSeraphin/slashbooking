<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

final class OAuthState
{
    public const TTL_SECONDS = 600;

    public function __construct(private readonly string $secret)
    {
    }

    public function issue(int $userId, ?int $now = null): string
    {
        $now ??= time();
        $exp = $now + self::TTL_SECONDS;
        $payload = sprintf('oauth|%d|%d', $userId, $exp);
        $sig = hash_hmac('sha256', $payload, $this->secret);
        return base64_encode($payload . '|' . $sig);
    }

    public function verify(string $token, ?int $now = null): ?int
    {
        $now ??= time();
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4 || $parts[0] !== 'oauth') {
            return null;
        }
        [, $userIdStr, $expStr, $sig] = $parts;
        if (!ctype_digit($userIdStr) || !ctype_digit($expStr)) {
            return null;
        }
        $exp = (int) $expStr;
        if ($exp < $now) {
            return null;
        }
        $expected = hash_hmac('sha256', "oauth|{$userIdStr}|{$expStr}", $this->secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return (int) $userIdStr;
    }
}
