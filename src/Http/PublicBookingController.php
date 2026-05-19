<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Availability\AvailabilityCalculator;
use Trinity\Booking\Availability\SlotGenerator;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\BusyBlockRepository;
use Trinity\Booking\Persistence\ServiceRepository;
use Trinity\Booking\Plugin;
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
            return new WP_Error('tb_service_not_found', 'Service introuvable', ['status' => 404]);
        }

        try {
            $tz   = new DateTimeZone(wp_timezone_string());
            $from = new DateTimeImmutable((string) $request['from'], $tz);
            $to   = new DateTimeImmutable((string) $request['to'], $tz);
        } catch (\Exception $e) {
            return new WP_Error('tb_invalid_date', 'Date invalide', ['status' => 400]);
        }
        if ($from >= $to) {
            return new WP_Error('tb_invalid_date', 'from doit précéder to', ['status' => 400]);
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
}
