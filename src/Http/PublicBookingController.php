<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Persistence\ServiceRepository;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicBookingController
{
    public function __construct(private readonly ServiceRepository $services)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/services',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listServices'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function listServices(WP_REST_Request $request): WP_REST_Response
    {
        $services = $this->services->findAllActive();
        $data = array_map(static fn ($s) => [
            'id'              => $s->id,
            'slug'            => $s->slug,
            'name'            => $s->name,
            'duration_min'    => $s->durationMin,
            'color'           => $s->color,
            'weekly_hours'    => $s->weeklyHours,
            'min_lead_hours'  => $s->minLeadTimeHours,
            'max_horizon_days'=> $s->maxHorizonDays,
        ], $services);

        return new WP_REST_Response($data, 200);
    }
}
