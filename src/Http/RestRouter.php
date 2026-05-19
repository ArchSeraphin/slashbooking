<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

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
        (new PublicBookingController($services))->registerRoutes();
    }
}
