<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Availability\AvailabilityCalculator;
use Slash\Booking\Availability\SlotGenerator;
use Slash\Booking\Booking\CreateBooking;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\BusyBlockRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;
use Slash\Booking\PublicFront\TurnstileVerifier;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicBookingController
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly BusyBlockRepository $busyBlocks,
        private readonly SlotGenerator $slotGenerator,
        private readonly CreateBooking $createBooking,
        private readonly ?TurnstileVerifier $turnstile = null,
    ) {
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

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/availability',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getAvailability'],
                'permission_callback' => '__return_true',
                'args' => [
                    'service' => ['type' => 'string', 'required' => true],
                    'from'    => ['type' => 'string', 'required' => true],
                    'to'      => ['type' => 'string', 'required' => true],
                ],
            ]
        );

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/bookings',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createBooking'],
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

    public function getAvailability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $svc = $this->services->findBySlug((string) $request['service']);
        if ($svc === null) {
            return new WP_Error('sb_service_not_found', __('Service introuvable', 'slashbooking'), ['status' => 404]);
        }

        try {
            $tz   = new DateTimeZone(wp_timezone_string());
            $from = new DateTimeImmutable((string) $request['from'], $tz);
            $to   = new DateTimeImmutable((string) $request['to'], $tz);
        } catch (\Exception $e) {
            return new WP_Error('sb_invalid_date', __('Date invalide', 'slashbooking'), ['status' => 400]);
        }
        if ($from >= $to) {
            return new WP_Error('sb_invalid_date', __('from doit précéder to', 'slashbooking'), ['status' => 400]);
        }

        $candidates = $this->slotGenerator->generate($svc, $from, $to);
        if ($candidates === []) {
            return new WP_REST_Response(['slots' => []], 200);
        }

        $rangeStart = $candidates[0]->start;
        $rangeEnd   = $candidates[count($candidates) - 1]->end;

        $blocking = array_map(
            static fn ($b) => $b->slot(),
            $this->bookings->findOverlapping(
                (int) ($svc->id ?? 0),
                new TimeSlot($rangeStart, $rangeEnd),
            ),
        );

        $busyEntries = $this->busyBlocks->findInRange($rangeStart, $rangeEnd);
        $busy = array_merge(
            $blocking,
            array_map(static fn ($bb) => $bb->slot, $busyEntries),
        );

        $calc = new AvailabilityCalculator(
            bufferBeforeMin: $svc->bufferBeforeMin,
            bufferAfterMin: $svc->bufferAfterMin,
        );
        $free = $calc->filter($candidates, $busy);

        $data = array_map(static fn ($s) => $s->toArray(), $free);
        return new WP_REST_Response(['slots' => $data], 200);
    }

    public function createBooking(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: [];

        // Honeypot
        if (!empty($params['website'])) {
            return new WP_REST_Response(['public_uid' => 'honeypot'], 201);
        }

        // Cloudflare Turnstile — only enforced if secret key is configured.
        if ($this->turnstile !== null && $this->turnstile->isConfigured()) {
            $token = (string) ($params['cf_turnstile_response'] ?? '');
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                return new WP_Error(
                    'sb_captcha_failed',
                    __('Vérification anti-robot échouée. Merci de rééssayer.', 'slashbooking'),
                    ['status' => 400]
                );
            }
        }

        if ($this->isRateLimited()) {
            return new WP_Error('sb_rate_limited', __('Trop de requêtes', 'slashbooking'), ['status' => 429]);
        }

        $svc = $this->services->findBySlug((string) ($params['service'] ?? ''));
        if ($svc === null) {
            return new WP_Error('sb_service_not_found', __('Service introuvable', 'slashbooking'), ['status' => 404]);
        }

        try {
            $start = new DateTimeImmutable((string) ($params['start'] ?? ''), new DateTimeZone('UTC'));
            $start = $start->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return new WP_Error('sb_invalid_date', __('start invalide', 'slashbooking'), ['status' => 400]);
        }
        $end = $start->modify('+' . $svc->durationMin . ' minutes');
        $slot = new TimeSlot($start, $end);

        $cmd = [
            'service'          => $svc,
            'slot'             => $slot,
            'timezone'         => wp_timezone_string(),
            'customer_name'    => sanitize_text_field((string) ($params['customer_name'] ?? '')),
            'customer_email'   => sanitize_email((string) ($params['customer_email'] ?? '')),
            'customer_phone'   => sanitize_text_field((string) ($params['customer_phone'] ?? '')),
            'customer_address' => sanitize_textarea_field((string) ($params['customer_address'] ?? '')),
            'customer_meta'    => is_array($params['customer_meta'] ?? null) ? $params['customer_meta'] : [],
            'notes'            => sanitize_textarea_field((string) ($params['notes'] ?? '')),
            'consent'          => (bool) ($params['consent'] ?? false),
        ];

        try {
            $booking = $this->createBooking->execute($cmd);
        } catch (\Slash\Booking\Booking\Exceptions\InvalidBookingInput $e) {
            return new WP_Error('sb_invalid_input', __('Champs invalides', 'slashbooking'), ['status' => 422, 'errors' => $e->errors]);
        } catch (\Slash\Booking\Booking\Exceptions\SlotUnavailable $e) {
            return new WP_Error('sb_slot_unavailable', __('Créneau indisponible', 'slashbooking'), ['status' => 409]);
        }

        return new WP_REST_Response([
            'public_uid' => $booking->publicUid(),
            'status'     => $booking->status()->value,
        ], 201);
    }

    private function isRateLimited(): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        $key = 'sb_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 5) {
            return true;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }
}
