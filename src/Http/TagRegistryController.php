<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Notifications\TagRegistry;
use Trinity\Booking\Plugin;
use WP_REST_Response;

final class TagRegistryController
{
    public function __construct(private readonly TagRegistry $registry = new TagRegistry())
    {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/tags', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list'],
                'permission_callback' => $cap,
            ],
        ]);
    }

    public function list(): WP_REST_Response
    {
        $grouped = $this->registry->grouped();
        $groups  = [];
        foreach ($grouped as $category => $tags) {
            $groups[] = [
                'category' => $category,
                'label'    => $this->categoryLabel($category),
                'tags'     => $tags,
            ];
        }
        return new WP_REST_Response(['groups' => $groups], 200);
    }

    private function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'customer'    => __('Client', 'trinity-booking'),
            'appointment' => __('Rendez-vous', 'trinity-booking'),
            'actions'     => __('Liens d\'action', 'trinity-booking'),
            'site'        => __('Site', 'trinity-booking'),
            default       => $cat,
        };
    }
}
