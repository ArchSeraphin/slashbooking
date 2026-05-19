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
}
