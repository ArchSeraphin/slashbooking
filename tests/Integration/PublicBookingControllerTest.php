<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use Trinity\Booking\Activator;

final class PublicBookingControllerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
    }

    public function test_get_services_returns_active_list(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/services');
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        $slugs = array_column($data, 'slug');
        self::assertSame(['pv', 'irve'], $slugs);
    }

    public function test_get_availability_returns_slots(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'pv',
            'from'    => '2026-06-01',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('slots', $data);
        self::assertNotEmpty($data['slots']);
        self::assertArrayHasKey('start', $data['slots'][0]);
    }

    public function test_get_availability_unknown_service_returns_404(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'inconnu',
            'from'    => '2026-06-01',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(404, $response->get_status());
    }

    public function test_get_availability_invalid_date_returns_400(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'pv',
            'from'    => 'oops',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(400, $response->get_status());
    }

    public function test_post_booking_happy_path(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Jean Test',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X, Paris',
            'notes' => '',
            'consent' => true,
            'website' => '',
        ]));
        $response = rest_do_request($request);
        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('public_uid', $data);
    }

    public function test_post_booking_rejects_missing_consent(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Jean',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'consent' => false,
        ]));
        $response = rest_do_request($request);
        self::assertSame(422, $response->get_status());
    }

    public function test_post_booking_honeypot_returns_201_silently(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Bot',
            'customer_email' => 'bot@spam.com',
            'customer_phone' => '0600',
            'customer_address' => 'x',
            'consent' => true,
            'website' => 'http://spam.tld',
        ]));
        $response = rest_do_request($request);
        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertSame('honeypot', $data['public_uid']);
    }

    public function test_post_booking_double_booking_returns_409(): void
    {
        $body = [
            'service' => 'pv',
            'start'   => '2026-06-02T07:00:00+00:00',
            'customer_name' => 'A',
            'customer_email' => 'a@a.fr',
            'customer_phone' => '0600',
            'customer_address' => 'x',
            'consent' => true,
        ];
        $r1 = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r1->set_header('content-type', 'application/json');
        $r1->set_body(json_encode($body)); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $resp1 = rest_do_request($r1);
        self::assertSame(201, $resp1->get_status());

        $r2 = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r2->set_header('content-type', 'application/json');
        $r2->set_body(json_encode($body)); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        $resp2 = rest_do_request($r2);
        self::assertSame(409, $resp2->get_status());
    }

    private function signCancel(string $uid, int $exp): string
    {
        $secret = get_option('tb_decision_secret');
        return hash_hmac('sha256', 'cancel|' . $uid . '|' . $exp, $secret);
    }

    public function test_cancel_with_valid_token_returns_200(): void
    {
        $r = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r->set_header('content-type', 'application/json');
        $r->set_body(json_encode([ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
            'service' => 'pv', 'start' => '2026-06-10T07:00:00+00:00',
            'customer_name' => 'A', 'customer_email' => 'a@a.fr',
            'customer_phone' => '0600', 'customer_address' => 'x', 'consent' => true,
        ]));
        $resp = rest_do_request($r);
        $uid = $resp->get_data()['public_uid'];

        $exp = time() + 3600;
        $sig = $this->signCancel($uid, $exp);
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/cancel');
        $req->set_query_params(['uid' => $uid, 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($req);
        self::assertSame(200, $response->get_status());
    }

    public function test_cancel_with_bad_signature_returns_403(): void
    {
        $exp = time() + 3600;
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/cancel');
        $req->set_query_params(['uid' => 'fake', 'exp' => $exp, 'sig' => 'wrong']);
        $response = rest_do_request($req);
        self::assertSame(403, $response->get_status());
    }
}
