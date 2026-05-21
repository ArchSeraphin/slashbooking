<?php
declare(strict_types=1);

namespace Slash\Booking\PublicFront;

use Closure;

/**
 * Cloudflare Turnstile token verifier.
 *
 * Fail-open when no secret key is configured (returns true): lets existing
 * installs keep working without Turnstile, and lets the admin disable it
 * by clearing the keys.
 *
 * @phpstan-type SiteverifyResponse array{
 *   success?: bool,
 *   challenge_ts?: string,
 *   hostname?: string,
 *   "error-codes"?: list<string>
 * }
 */
final class TurnstileVerifier
{
    public const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * @param Closure(string, array<string, string>): ?array<string, mixed>|null $httpPost
     *   Injectable HTTP callable for tests. Default uses wp_remote_post.
     */
    public function __construct(
        private readonly string $secret,
        private readonly ?Closure $httpPost = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    public function verify(string $token, ?string $ip = null): bool
    {
        if (!$this->isConfigured()) {
            return true; // fail-open: Turnstile disabled
        }
        if (trim($token) === '') {
            return false;
        }

        $body = ['secret' => $this->secret, 'response' => $token];
        if ($ip !== null && $ip !== '') {
            $body['remoteip'] = $ip;
        }

        $data = ($this->httpPost ?? $this->defaultPost(...))(self::ENDPOINT, $body);
        if (!is_array($data)) {
            return false;
        }
        return ($data['success'] ?? false) === true;
    }

    /**
     * @param array<string, string> $body
     * @return array<string, mixed>|null
     */
    private function defaultPost(string $url, array $body): ?array
    {
        $resp = wp_remote_post($url, [
            'timeout' => 5,
            'body'    => $body,
        ]);
        if (is_wp_error($resp)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($json) ? $json : null;
    }
}
