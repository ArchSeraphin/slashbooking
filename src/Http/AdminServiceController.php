<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Domain\Service;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminServiceController
{
    public function __construct(
        private readonly ServiceRepository $repo,
        private readonly ?BookingRepository $bookings = null,
    ) {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);
        $ns  = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/services', [
            ['methods' => 'GET',  'callback' => [$this, 'list'],   'permission_callback' => $cap],
            ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/services/(?P<slug>[a-z0-9_\-]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],    'permission_callback' => $cap],
            ['methods' => 'POST',   'callback' => [$this, 'update'], 'permission_callback' => $cap],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete'], 'permission_callback' => $cap],
        ]);
    }

    public function list(): WP_REST_Response
    {
        $services = $this->repo->findAll();
        return new WP_REST_Response([
            'services' => array_map(fn (Service $s) => $this->serialize($s), $services),
        ], 200);
    }

    public function get(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $slug = (string) $req->get_param('slug');
        $svc = $this->repo->findBySlug($slug);
        if ($svc === null) {
            return new WP_Error('sb_service_not_found', __('Service introuvable', 'slashbooking'), ['status' => 404]);
        }
        return new WP_REST_Response($this->serialize($svc), 200);
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $name = trim((string) $req->get_param('name'));
        if ($name === '') {
            return new WP_Error('sb_invalid_name', __('Le nom du service est requis.', 'slashbooking'), ['status' => 400]);
        }

        $rawSlug = trim((string) $req->get_param('slug'));
        $slug = $rawSlug !== '' ? $this->slugify($rawSlug) : $this->slugify($name);
        if ($slug === '') {
            return new WP_Error('sb_invalid_slug', __('Slug invalide.', 'slashbooking'), ['status' => 400]);
        }

        // Ensure uniqueness by appending -2, -3, ... if needed.
        $finalSlug = $slug;
        $attempt   = 2;
        while ($this->repo->findBySlug($finalSlug) !== null) {
            $finalSlug = $slug . '-' . $attempt;
            $attempt++;
            if ($attempt > 50) {
                return new WP_Error('sb_slug_collision', __('Impossible de générer un slug unique.', 'slashbooking'), ['status' => 409]);
            }
        }

        // Sensible defaults: 60 min, Mon-Fri 9-18, active, 24h lead time, 60-day horizon.
        $defaultWeekly = [
            1 => [['open' => '09:00', 'close' => '18:00']],
            2 => [['open' => '09:00', 'close' => '18:00']],
            3 => [['open' => '09:00', 'close' => '18:00']],
            4 => [['open' => '09:00', 'close' => '18:00']],
            5 => [['open' => '09:00', 'close' => '18:00']],
        ];

        try {
            $svc = new Service(
                id: null,
                slug: $finalSlug,
                name: sanitize_text_field($name),
                durationMin: 60,
                bufferBeforeMin: 0,
                bufferAfterMin: 30,
                minLeadTimeHours: 24,
                maxHorizonDays: 60,
                weeklyHours: $defaultWeekly,
                active: true,
                color: '#2563eb',
            );
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('sb_invalid_service', $e->getMessage(), ['status' => 400]);
        }

        $id = $this->repo->create($svc);
        if ($id === null) {
            return new WP_Error('sb_create_failed', __('Échec de la création.', 'slashbooking'), ['status' => 500]);
        }

        // Fetch by id (not slug) — PHPStan's flow analysis remembers the prior
        // findBySlug() === null check from the uniqueness loop and would otherwise
        // believe this lookup also returns null.
        $created = $this->repo->findById($id);
        if ($created === null) {
            return new WP_Error('sb_create_failed', __('Service créé introuvable.', 'slashbooking'), ['status' => 500]);
        }
        return new WP_REST_Response($this->serialize($created), 201);
    }

    public function delete(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $slug = (string) $req->get_param('slug');
        $svc = $this->repo->findBySlug($slug);
        if ($svc === null) {
            return new WP_Error('sb_service_not_found', __('Service introuvable', 'slashbooking'), ['status' => 404]);
        }

        if ($this->bookings !== null && $svc->id !== null) {
            $count = $this->bookings->countByServiceId($svc->id);
            if ($count > 0) {
                return new WP_Error(
                    'sb_service_has_bookings',
                    sprintf(
                        /* translators: %d = number of bookings linked to the service */
                        __('Ce service compte %d réservation(s) liée(s). Désactivez-le plutôt que de le supprimer pour préserver l\'historique.', 'slashbooking'),
                        $count
                    ),
                    ['status' => 409]
                );
            }
        }

        if ($svc->id === null || !$this->repo->delete($svc->id)) {
            return new WP_Error('sb_delete_failed', __('Échec de la suppression.', 'slashbooking'), ['status' => 500]);
        }

        return new WP_REST_Response(['deleted' => true, 'slug' => $slug], 200);
    }

    private function slugify(string $input): string
    {
        $s = strtolower($input);
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s;
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $slug = (string) $req->get_param('slug');
        $current = $this->repo->findBySlug($slug);
        if ($current === null) {
            return new WP_Error('sb_service_not_found', __('Service introuvable', 'slashbooking'), ['status' => 404]);
        }

        $name             = $this->str($req->get_param('name'), $current->name);
        $durationMin      = $this->intIn($req->get_param('duration_min'), 5, 600, $current->durationMin);
        $bufferBeforeMin  = $this->intIn($req->get_param('buffer_before_min'), 0, 240, $current->bufferBeforeMin);
        $bufferAfterMin   = $this->intIn($req->get_param('buffer_after_min'), 0, 240, $current->bufferAfterMin);
        $minLeadTimeHours = $this->intIn($req->get_param('min_lead_time_hours'), 0, 720, $current->minLeadTimeHours);
        $maxHorizonDays   = $this->intIn($req->get_param('max_horizon_days'), 1, 365, $current->maxHorizonDays);
        $color            = $this->str($req->get_param('color'), $current->color);
        $active           = (bool) ($req->get_param('active') ?? $current->active);

        $weeklyParam = $req->get_param('weekly_hours');
        $weekly = $this->normalizeWeeklyHours($weeklyParam, $current->weeklyHours);

        try {
            $updated = new Service(
                id: $current->id,
                slug: $current->slug,
                name: $name,
                durationMin: $durationMin,
                bufferBeforeMin: $bufferBeforeMin,
                bufferAfterMin: $bufferAfterMin,
                minLeadTimeHours: $minLeadTimeHours,
                maxHorizonDays: $maxHorizonDays,
                weeklyHours: $weekly,
                active: $active,
                color: $color,
            );
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('sb_invalid_service', $e->getMessage(), ['status' => 400]);
        }

        if (!$this->repo->update($updated)) {
            return new WP_Error('sb_save_failed', __('Échec de la sauvegarde.', 'slashbooking'), ['status' => 500]);
        }

        return new WP_REST_Response($this->serialize($updated), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Service $s): array
    {
        return [
            'id'                  => $s->id,
            'slug'                => $s->slug,
            'name'                => $s->name,
            'duration_min'        => $s->durationMin,
            'buffer_before_min'   => $s->bufferBeforeMin,
            'buffer_after_min'    => $s->bufferAfterMin,
            'min_lead_time_hours' => $s->minLeadTimeHours,
            'max_horizon_days'    => $s->maxHorizonDays,
            'color'               => $s->color,
            'active'              => $s->active,
            'weekly_hours'        => $this->serializeWeeklyHours($s->weeklyHours),
        ];
    }

    /**
     * Convert the int-keyed weeklyHours (1..7) to a stable serializable form
     * with all 7 days present (empty array = closed). PHP coerces numeric
     * string keys to int internally, but json_encode emits them as strings.
     *
     * @param array<int, list<array{open:string, close:string}>> $weekly
     * @return array<int, list<array{open:string, close:string}>>
     */
    private function serializeWeeklyHours(array $weekly): array
    {
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            $out[$d] = $weekly[$d] ?? [];
        }
        return $out;
    }

    /**
     * Validates and normalizes incoming weekly_hours payload.
     * Expected shape: { "1": [{open:"09:00", close:"18:00"}, ...], "2": [...], ..., "7": [...] }
     *
     * @param mixed $param
     * @param array<int, list<array{open:string, close:string}>> $fallback
     * @return array<int, list<array{open:string, close:string}>>
     */
    private function normalizeWeeklyHours(mixed $param, array $fallback): array
    {
        if (!is_array($param)) {
            return $fallback;
        }
        $out = [];
        for ($d = 1; $d <= 7; $d++) {
            $key = (string) $d;
            $ranges = $param[$key] ?? $param[$d] ?? [];
            if (!is_array($ranges)) {
                continue;
            }
            $normalized = [];
            foreach ($ranges as $r) {
                if (!is_array($r)) continue;
                $open  = isset($r['open'])  ? $this->time((string) $r['open']) : null;
                $close = isset($r['close']) ? $this->time((string) $r['close']) : null;
                if ($open === null || $close === null || $open >= $close) {
                    continue;
                }
                $normalized[] = ['open' => $open, 'close' => $close];
            }
            if ($normalized !== []) {
                $out[$d] = $normalized;
            }
        }
        return $out;
    }

    private function time(string $hhmm): ?string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hhmm)) {
            return null;
        }
        return $hhmm;
    }

    private function str(mixed $v, string $fallback): string
    {
        if (!is_string($v) || trim($v) === '') return $fallback;
        return sanitize_text_field($v);
    }

    private function intIn(mixed $v, int $min, int $max, int $fallback): int
    {
        if ($v === null || $v === '') return $fallback;
        $i = (int) $v;
        if ($i < $min) return $min;
        if ($i > $max) return $max;
        return $i;
    }
}
