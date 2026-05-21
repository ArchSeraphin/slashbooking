<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Closure;
use Slash\Booking\Persistence\GoogleAccountRepository;
use Slash\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class GoogleWebhookController
{
    /**
     * @param Closure(int): void                  $enqueuePull
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly Closure $enqueuePull,
        private readonly Closure $log,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/google/webhook',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token         = (string) $request->get_header('X-Goog-Channel-Token');
        $channelId     = (string) $request->get_header('X-Goog-Channel-Id');
        $resourceState = (string) $request->get_header('X-Goog-Resource-State');

        $account = $this->accounts->findSingle();
        if ($account === null || !$account->verifyWatchToken($token)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => null,
                'google_event_id' => null,
                'action'          => 'webhook_rejected',
                'status'          => 'failed',
                'error_message'   => 'token mismatch',
                'payload'         => ['channelId' => $channelId, 'state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => false], 401);
        }

        if ($account->watchChannelId() !== null && $account->watchChannelId() !== $channelId) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_stale_channel',
                'status'          => 'failed',
                'error_message'   => "received channelId={$channelId}, expected {$account->watchChannelId()}",
                'payload'         => ['state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        if ($resourceState === 'sync') {
            ($this->log)([
                'level'           => 'info',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_sync_ack',
                'status'          => 'ok',
                'error_message'   => null,
                'payload'         => ['channelId' => $channelId],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        ($this->enqueuePull)((int) $account->id());

        ($this->log)([
            'level'           => 'info',
            'direction'       => 'internal',
            'entity'          => 'watch',
            'entity_id'       => $account->id(),
            'google_event_id' => null,
            'action'          => 'webhook_received',
            'status'          => 'ok',
            'error_message'   => null,
            'payload'         => ['state' => $resourceState],
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
