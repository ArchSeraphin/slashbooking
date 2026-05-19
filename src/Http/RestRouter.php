<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Availability\SlotGenerator;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\BusyBlockRepository;
use Trinity\Booking\Persistence\ServiceRepository;

final class RestRouter
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $busy     = new BusyBlockRepository($wpdb);
        $generator = new SlotGenerator(
            stepMinutes: 15,
            siteTimezone: wp_timezone_string(),
        );

        (new PublicBookingController($services, $bookings, $busy, $generator))->registerRoutes();
    }
}
