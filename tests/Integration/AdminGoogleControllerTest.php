<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class AdminGoogleControllerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $httpStub = [];

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_google_accounts");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");

        update_option('tb_decision_secret', str_repeat('a', 64), false);
        update_option('tb_google_client_id', 'cid');
        update_option('tb_google_client_secret', 'csecret');

        $this->httpStub = [];
        add_filter('pre_http_request', [$this, 'interceptHttp'], 10, 3);

        wp_set_current_user(1);
        $user = wp_get_current_user();
        $user->add_cap('trinity_booking_manage');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptHttp'], 10);
    }

    public function interceptHttp(mixed $preempt, array $args, string $url): array
    {
        $this->httpStub[] = compact('args', 'url');
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => (string) wp_json_encode([
                    'access_token'  => 'access-XYZ',
                    'refresh_token' => 'refresh-XYZ',
                    'expires_in'    => 3600,
                    'scope'         => 'https://www.googleapis.com/auth/calendar.events',
                    'token_type'    => 'Bearer',
                ]),
                'headers'  => [],
                'cookies'  => [],
            ];
        }
        return ['response' => ['code' => 500, 'message' => 'KO'], 'body' => '', 'headers' => [], 'cookies' => []];
    }

    public function test_start_returns_auth_url_with_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('POST', '/trinity-booking/v1/admin/google/oauth/start');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('auth_url', $data);
        self::assertStringContainsString('accounts.google.com', (string) $data['auth_url']);
        self::assertStringContainsString('state=', (string) $data['auth_url']);
    }

    public function test_callback_exchanges_code_and_persists_account(): void
    {
        do_action('rest_api_init');
        global $wpdb;

        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => $state]);

        $res = rest_do_request($req);
        self::assertNotSame(403, $res->get_status());

        $repo = new GoogleAccountRepository($wpdb);
        $acct = $repo->findSingle();
        self::assertNotNull($acct);
        // Refresh token must be encrypted (not raw).
        self::assertNotSame('refresh-XYZ', $acct->refreshTokenEnc());
    }

    public function test_callback_rejects_invalid_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => 'garbage']);
        $res = rest_do_request($req);
        self::assertSame(403, $res->get_status());
    }

    public function test_status_returns_connected_after_callback(): void
    {
        do_action('rest_api_init');

        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/status');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        $data = $res->get_data();
        self::assertTrue($data['connected']);
        self::assertSame('primary', $data['calendar_id']);
    }

    public function test_disconnect_removes_account(): void
    {
        do_action('rest_api_init');

        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('POST', '/trinity-booking/v1/admin/google/disconnect');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());

        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);
        self::assertNull($repo->findSingle());
    }
}
