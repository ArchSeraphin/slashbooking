<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Persistence\SyncLogRepository;
use Slash\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminSyncLogController
{
    public function __construct(private readonly SyncLogRepository $log)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Plugin::REST_NAMESPACE, '/admin/sync-log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        $page    = max(1, (int) $req->get_param('page'));
        $perPage = min(200, max(1, (int) ($req->get_param('per_page') ?: 50)));
        /** @var array{level?: string, direction?: string, status?: string, entity_id?: int} $filters */
        $filters = [];
        foreach (['level', 'direction', 'status'] as $f) {
            $v = $req->get_param($f);
            if (is_string($v) && $v !== '') {
                $filters[$f] = $v;
            }
        }
        $entityId = (int) $req->get_param('entity_id');
        if ($entityId > 0) {
            $filters['entity_id'] = $entityId;
        }
        return new WP_REST_Response($this->log->paginate($filters, $page, $perPage), 200);
    }
}
