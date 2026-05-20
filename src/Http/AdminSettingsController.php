<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminSettingsController
{
    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/settings', [
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
        $legalId = (int) get_option('tb_legal_page_id', 0);
        $url     = $legalId > 0 ? (string) get_permalink($legalId) : '';
        return new WP_REST_Response([
            'legal_page_id'          => $legalId,
            'legal_url'              => $url,
            'booking_retention_days' => (int) get_option('tb_booking_retention_days', 1095),
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $legalId   = (int) $req->get_param('legal_page_id');
        $retention = (int) $req->get_param('booking_retention_days');

        update_option('tb_legal_page_id', max(0, $legalId), false);
        if ($retention >= 30 && $retention <= 3650) {
            update_option('tb_booking_retention_days', $retention, false);
        }

        return new WP_REST_Response(['saved' => true], 200);
    }
}
