<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminGoogleSettingsController
{
    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/google/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'read'],
                'permission_callback' => $cap,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'write'],
                'permission_callback' => $cap,
            ],
        ]);
    }

    public function read(): WP_REST_Response
    {
        $secret = (string) get_option('tb_google_client_secret', '');
        return new WP_REST_Response([
            'client_id'         => (string) get_option('tb_google_client_id', ''),
            'has_client_secret' => $secret !== '',
            'redirect_uri'      => rest_url(Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $clientId = sanitize_text_field((string) $req->get_param('client_id'));
        $secret   = (string) $req->get_param('client_secret');
        update_option('tb_google_client_id', $clientId, false);
        if ($secret !== '') {
            update_option('tb_google_client_secret', $secret, false);
        }
        return new WP_REST_Response(['saved' => true], 200);
    }
}
