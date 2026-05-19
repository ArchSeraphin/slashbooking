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
}
