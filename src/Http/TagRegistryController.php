<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Plugin;
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
            'customer'    => __('Client', 'slashbooking'),
            'appointment' => __('Rendez-vous', 'slashbooking'),
            'actions'     => __('Liens d\'action', 'slashbooking'),
            'site'        => __('Site', 'slashbooking'),
            default       => $cat,
        };
    }
}
