<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client as GoogleClient;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\Exceptions\OAuthFailure;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class GoogleClientBuilder
{
    public function __construct(
        private readonly Encryption $encryption,
        private readonly GoogleAccountRepository $accounts,
    ) {
    }

    public function buildGateway(GoogleAccount $account): CalendarGateway
    {
        $client = new GoogleClient();
        $client->setClientId((string) get_option('tb_google_client_id', ''));
        $client->setClientSecret((string) get_option('tb_google_client_secret', ''));
        $client->addScope(OAuthClient::SCOPE);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now->modify('+30 seconds'))) {
            $this->refresh($account, $client);
        } else {
            $client->setAccessToken([
                'access_token' => $this->encryption->decrypt($account->accessTokenEnc()),
                'expires_in'   => max(0, $account->expiresAt()->getTimestamp() - $now->getTimestamp()),
                'created'      => $now->getTimestamp(),
            ]);
        }

        return new GoogleApiCalendarGateway($client);
    }

    private function refresh(GoogleAccount $account, GoogleClient $client): void
    {
        $oauth = new OAuthClient(
            clientId: (string) get_option('tb_google_client_id', ''),
            clientSecret: (string) get_option('tb_google_client_secret', ''),
            redirectUri: '',
        );
        try {
            $refresh = $this->encryption->decrypt($account->refreshTokenEnc());
            $tokens = $oauth->refreshAccessToken($refresh);
        } catch (\Throwable $e) {
            throw new OAuthFailure('Refresh failed: ' . $e->getMessage(), 0, $e);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . (int) $tokens['expires_in'] . ' seconds');
        $account->rotateAccessToken($this->encryption->encrypt((string) $tokens['access_token']), $expiresAt);
        $this->accounts->save($account);

        $client->setAccessToken([
            'access_token' => (string) $tokens['access_token'],
            'expires_in'   => (int) $tokens['expires_in'],
            'created'      => $now->getTimestamp(),
        ]);
    }
}
