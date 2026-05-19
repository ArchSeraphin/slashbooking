<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

final class RestRouter
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // Controllers self-register here in later tasks (T24+).
    }
}
