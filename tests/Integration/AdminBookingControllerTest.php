<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Persistence\BookingRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class AdminBookingControllerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
    }

    public function test_list_requires_capability(): void
    {
        wp_set_current_user(0);
        $r = new WP_REST_Request('GET', '/slashbooking/v1/admin/bookings');
        self::assertSame(401, rest_do_request($r)->get_status());
    }

    public function test_list_returns_paginated_results_for_admin(): void
    {
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $this->seed(3);

        $r = new WP_REST_Request('GET', '/slashbooking/v1/admin/bookings');
        $r->set_query_params(['per_page' => 2]);
        $resp = rest_do_request($r);
        self::assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        self::assertSame(3, $data['total']);
        self::assertCount(2, $data['items']);
    }

    public function test_confirm_endpoint_transitions_status(): void
    {
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $b = $this->seed(1)[0];

        $r = new WP_REST_Request('POST', '/slashbooking/v1/admin/bookings/' . $b->id() . '/confirm');
        $resp = rest_do_request($r);
        self::assertSame(200, $resp->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::CONFIRMED, $refreshed->status());
    }

    public function test_cancel_endpoint_transitions_status(): void
    {
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $b = $this->seed(1)[0];

        $r = new WP_REST_Request('POST', '/slashbooking/v1/admin/bookings/' . $b->id() . '/cancel');
        $resp = rest_do_request($r);
        self::assertSame(200, $resp->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::CANCELLED, $refreshed->status());
    }

    /**
     * @return list<Booking>
     */
    private function seed(int $count): array
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $out  = [];
        for ($i = 0; $i < $count; $i++) {
            $slot = new TimeSlot(
                new DateTimeImmutable('2026-06-0' . ($i + 1) . 'T08:00:00Z', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-06-0' . ($i + 1) . 'T09:30:00Z', new DateTimeZone('UTC')),
            );
            $b = Booking::createPending(
                serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
                customerName: 'C' . $i, customerEmail: 'c' . $i . '@x.fr',
                customerPhone: '0600', customerAddress: 'x',
                customerMeta: [], notes: '',
            );
            $repo->save($b);
            $out[] = $b;
        }
        return $out;
    }
}
