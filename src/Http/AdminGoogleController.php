<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\Encryption;
use Trinity\Booking\Google\Exceptions\OAuthFailure;
use Trinity\Booking\Google\OAuthClient;
use Trinity\Booking\Google\OAuthState;
use Trinity\Booking\Persistence\GoogleAccountRepository;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminGoogleController
{
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly OAuthClient $oauthClient,
        private readonly OAuthState $state,
        private readonly Encryption $encryption,
    ) {
    }

    public function registerRoutes(): void
    {
        $ns = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/google/oauth/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/admin/google/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [$this, 'callback'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/admin/google/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/admin/google/disconnect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'disconnect'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(Capabilities::MANAGE);
    }

    public function start(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return new WP_Error('not_logged_in', 'Not logged in', ['status' => 401]);
        }
        $stateToken = $this->state->issue($userId);
        $url        = $this->oauthClient->authUrl($stateToken);
        return new WP_REST_Response(['auth_url' => $url], 200);
    }

    public function callback(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $code  = (string) $req->get_param('code');
        $state = (string) $req->get_param('state');

        if ($code === '' || $this->state->verify($state) === null) {
            return new WP_Error('invalid_state', 'Invalid or expired OAuth state.', ['status' => 403]);
        }

        try {
            $tokens = $this->oauthClient->exchangeCode($code);
        } catch (OAuthFailure $e) {
            return new WP_Error('oauth_failed', $e->getMessage(), ['status' => 502]);
        }

        if (!isset($tokens['refresh_token'])) {
            return new WP_Error(
                'missing_refresh_token',
                'Google did not return a refresh token. Revoke access at myaccount.google.com and retry with prompt=consent.',
                ['status' => 502]
            );
        }

        $existing  = $this->accounts->findSingle();
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . (int) $tokens['expires_in'] . ' seconds');

        $refreshEnc = $this->encryption->encrypt((string) $tokens['refresh_token']);
        $accessEnc  = $this->encryption->encrypt((string) $tokens['access_token']);

        $label      = $existing?->label() ?? 'Commercial Trinity';
        $calendarId = $existing?->calendarId() ?? 'primary';

        $account = GoogleAccount::connect(
            label: $label,
            calendarId: $calendarId,
            refreshTokenEnc: $refreshEnc,
            accessTokenEnc: $accessEnc,
            expiresAt: $expiresAt,
        );
        if ($existing !== null && $existing->id() !== null) {
            $account->assignId($existing->id());
        }

        $this->accounts->save($account);

        $redirect = admin_url('admin.php?page=trinity-booking#/google?connected=1');
        return new WP_REST_Response(null, 302, ['Location' => $redirect]);
    }

    public function status(): WP_REST_Response
    {
        $acct = $this->accounts->findSingle();
        if ($acct === null) {
            return new WP_REST_Response(['connected' => false], 200);
        }
        return new WP_REST_Response([
            'connected'       => true,
            'calendar_id'     => $acct->calendarId(),
            'label'           => $acct->label(),
            'expires_at'      => $acct->expiresAt()->format(\DateTimeInterface::ATOM),
            'connected_since' => $acct->createdAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }

    public function disconnect(): WP_REST_Response
    {
        $acct = $this->accounts->findSingle();
        if ($acct !== null && $acct->id() !== null) {
            $this->accounts->delete($acct->id());
        }
        return new WP_REST_Response(['disconnected' => true], 200);
    }
}
