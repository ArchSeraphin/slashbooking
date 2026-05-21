<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Plugin;
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
        $legalId = (int) get_option('sb_legal_page_id', 0);
        $url     = $legalId > 0 ? (string) get_permalink($legalId) : '';
        return new WP_REST_Response([
            'legal_page_id'          => $legalId,
            'legal_url'              => $url,
            'booking_retention_days' => (int) get_option('sb_booking_retention_days', 1095),
            'notification_email'     => (string) get_option('sb_notification_email', ''),
            'admin_email_fallback'   => (string) get_option('admin_email', ''),
            'company_logo'           => (string) get_option('sb_company_logo', ''),
            'company_phone'          => (string) get_option('sb_company_phone', ''),
            'form_disclaimer'        => (string) get_option('sb_form_disclaimer', ''),
            'turnstile_site_key'     => (string) get_option('sb_turnstile_site_key', ''),
            // Don't expose the secret in cleartext — caller only needs to know if it's configured.
            'turnstile_secret_set'   => get_option('sb_turnstile_secret_key', '') !== '',
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $legalId   = (int) $req->get_param('legal_page_id');
        $retention = (int) $req->get_param('booking_retention_days');

        update_option('sb_legal_page_id', max(0, $legalId), false);
        if ($retention >= 30 && $retention <= 3650) {
            update_option('sb_booking_retention_days', $retention, false);
        }

        // Notification email — empty string clears the override (= fall back to admin_email).
        if ($req->has_param('notification_email')) {
            $email = trim((string) $req->get_param('notification_email'));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                update_option('sb_notification_email', $email, false);
            }
        }

        if ($req->has_param('company_logo')) {
            $logo = trim((string) $req->get_param('company_logo'));
            // Allow empty (clear) or a URL — light validation only.
            if ($logo === '' || filter_var($logo, FILTER_VALIDATE_URL)) {
                update_option('sb_company_logo', esc_url_raw($logo), false);
            }
        }

        if ($req->has_param('company_phone')) {
            $phone = trim((string) $req->get_param('company_phone'));
            update_option('sb_company_phone', sanitize_text_field($phone), false);
        }

        if ($req->has_param('form_disclaimer')) {
            $disclaimer = (string) $req->get_param('form_disclaimer');
            // Allow line breaks + basic punctuation; strip dangerous HTML.
            update_option('sb_form_disclaimer', wp_kses_post(trim($disclaimer)), false);
        }

        if ($req->has_param('turnstile_site_key')) {
            $key = trim((string) $req->get_param('turnstile_site_key'));
            update_option('sb_turnstile_site_key', sanitize_text_field($key), false);
        }

        // Secret key is write-only: empty string means "keep current"; non-empty
        // overwrites. The explicit sentinel "__CLEAR__" wipes the stored secret.
        if ($req->has_param('turnstile_secret_key')) {
            $secret = trim((string) $req->get_param('turnstile_secret_key'));
            if ($secret === '__CLEAR__') {
                delete_option('sb_turnstile_secret_key');
            } elseif ($secret !== '') {
                update_option('sb_turnstile_secret_key', sanitize_text_field($secret), false);
            }
        }

        return new WP_REST_Response(['saved' => true], 200);
    }
}
