<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

final class DecisionTokenSigner
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Decision secret must be at least 16 characters.');
        }
    }

    public function sign(string $payload, int $expiresAtUnix): string
    {
        return hash_hmac('sha256', $payload . '|' . $expiresAtUnix, $this->secret);
    }

    public function verify(string $payload, int $expiresAtUnix, string $signature): bool
    {
        if ($expiresAtUnix < time()) {
            return false;
        }
        $expected = $this->sign($payload, $expiresAtUnix);
        return hash_equals($expected, $signature);
    }
}
