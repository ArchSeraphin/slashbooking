<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Encryption;
use Slash\Booking\Google\Exceptions\OAuthFailure;
use Slash\Booking\Google\GoogleClientBuilder;
use Slash\Booking\Google\OAuthClient;
use Slash\Booking\Google\OAuthState;
use Slash\Booking\Google\WatchChannelManager;
use Slash\Booking\Persistence\GoogleAccountRepository;
use Slash\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminGoogleController
{
    /**
     * @param Closure(int): void $enqueuePull
     */
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly OAuthClient $oauthClient,
        private readonly OAuthState $state,
        private readonly Encryption $encryption,
        private readonly WatchChannelManager $watchManager,
        private readonly GoogleClientBuilder $clientBuilder,
        private readonly Closure $enqueuePull,
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
        register_rest_route($ns, '/admin/google/watch/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'watchStart'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
        register_rest_route($ns, '/admin/google/watch/stop', [
            'methods'             => 'POST',
            'callback'            => [$this, 'watchStop'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
        register_rest_route($ns, '/admin/google/pull/now', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pullNow'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
        register_rest_route($ns, '/admin/google/diagnostics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'diagnostics'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
        register_rest_route($ns, '/admin/google/calendars', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listCalendars'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/admin/google/calendar', [
            'methods'             => 'POST',
            'callback'            => [$this, 'setCalendar'],
            'permission_callback' => [$this, 'canManage'],
            'args'                => [
                'calendar_id' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
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
            return new WP_Error('not_logged_in', __('Not logged in', 'slashbooking'), ['status' => 401]);
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
            return new WP_Error('invalid_state', __('Invalid or expired OAuth state.', 'slashbooking'), ['status' => 403]);
        }

        try {
            $tokens = $this->oauthClient->exchangeCode($code);
        } catch (OAuthFailure $e) {
            return new WP_Error('oauth_failed', $e->getMessage(), ['status' => 502]);
        }

        if (!isset($tokens['refresh_token'])) {
            return new WP_Error(
                'missing_refresh_token',
                __('Google did not return a refresh token. Revoke access at myaccount.google.com and retry with prompt=consent.', 'slashbooking'),
                ['status' => 502]
            );
        }

        $existing  = $this->accounts->findSingle();
        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . (int) $tokens['expires_in'] . ' seconds');

        $refreshEnc = $this->encryption->encrypt((string) $tokens['refresh_token']);
        $accessEnc  = $this->encryption->encrypt((string) $tokens['access_token']);

        $label      = $existing?->label() ?? 'Commercial';
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

        $redirect = admin_url('admin.php?page=slashbooking#/google?connected=1');
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

    public function watchStart(): WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        try {
            $gateway    = $this->clientBuilder->buildGateway($account);
            $webhookUrl = rest_url(Plugin::REST_NAMESPACE . '/google/webhook');
            $this->watchManager->start($account, $gateway, $webhookUrl);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        return new WP_REST_Response([
            'ok'        => true,
            'channelId' => $account->watchChannelId(),
            'expiresAt' => $account->watchExpiresAt()?->format(DateTimeInterface::ATOM),
        ]);
    }

    public function watchStop(): WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        try {
            $gateway = $this->clientBuilder->buildGateway($account);
            $this->watchManager->stop($account, $gateway);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        return new WP_REST_Response(['ok' => true]);
    }

    public function pullNow(): WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        ($this->enqueuePull)((int) $account->id());
        return new WP_REST_Response(['ok' => true]);
    }

    public function listCalendars(): WP_REST_Response|WP_Error
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_Error('no_account', __('Aucun compte Google connecté.', 'slashbooking'), ['status' => 400]);
        }
        try {
            $gateway = $this->clientBuilder->buildGateway($account);
            $items   = $gateway->listCalendars();
        } catch (\Throwable $e) {
            return new WP_Error('list_failed', $e->getMessage(), ['status' => 502]);
        }
        return new WP_REST_Response([
            'selected'  => $account->calendarId(),
            'calendars' => $items,
        ], 200);
    }

    public function setCalendar(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $calendarId = trim((string) $req->get_param('calendar_id'));
        if ($calendarId === '') {
            return new WP_Error('invalid_calendar', __('Identifiant de calendrier requis.', 'slashbooking'), ['status' => 400]);
        }

        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_Error('no_account', __('Aucun compte Google connecté.', 'slashbooking'), ['status' => 400]);
        }

        $account->setCalendarId($calendarId);
        $this->accounts->save($account);

        return new WP_REST_Response([
            'ok'          => true,
            'calendar_id' => $account->calendarId(),
        ], 200);
    }

    public function diagnostics(): WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new WP_REST_Response(['connected' => false]);
        }
        return new WP_REST_Response([
            'connected'      => true,
            'label'          => $account->label(),
            'calendarId'     => $account->calendarId(),
            'tokenExpiresAt' => $account->expiresAt()->format(DateTimeInterface::ATOM),
            'watch'          => [
                'channelId'  => $account->watchChannelId(),
                'resourceId' => $account->watchResourceId(),
                'expiresAt'  => $account->watchExpiresAt()?->format(DateTimeInterface::ATOM),
            ],
            'syncToken'      => $account->syncToken() !== null,
            'lastFullSyncAt' => $account->lastFullSyncAt()?->format(DateTimeInterface::ATOM),
        ]);
    }
}
