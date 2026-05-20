<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Trinity\Booking\Google\Exceptions\OAuthFailure;

/**
 * @phpstan-type TokenResponse array{
 *   access_token: string,
 *   refresh_token?: string,
 *   expires_in: int,
 *   scope?: string,
 *   token_type?: string
 * }
 */
final class OAuthClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
    }

    public function authUrl(string $state): string
    {
        $params = [
            'response_type'          => 'code',
            'client_id'              => $this->clientId,
            'redirect_uri'           => $this->redirectUri,
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'state'                  => $state,
            'include_granted_scopes' => 'true',
        ];
        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * @return TokenResponse
     */
    public function exchangeCode(string $code): array
    {
        return $this->post([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
    }

    /**
     * @return TokenResponse
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->post([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
    }

    /**
     * @param array<string, string> $body
     * @return TokenResponse
     */
    private function post(array $body): array
    {
        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            throw new OAuthFailure('HTTP error: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $json   = json_decode($raw, true);

        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $err = is_array($json) && isset($json['error']) ? (string) $json['error'] : 'unknown';
            throw new OAuthFailure(sprintf('Google token endpoint returned %d (%s).', $status, $err));
        }

        if (!isset($json['access_token'], $json['expires_in'])) {
            throw new OAuthFailure('Google token response missing fields.');
        }

        /** @var TokenResponse $json */
        return $json;
    }
}
