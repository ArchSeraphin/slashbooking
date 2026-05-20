<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Booking\CancelBooking;
use Trinity\Booking\Booking\ConfirmBooking;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Booking\RejectBooking;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AdminBookingController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ConfirmBooking $confirm,
        private readonly RejectBooking  $reject,
        private readonly CancelBooking  $cancel,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/admin/bookings',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list'],
                'permission_callback' => [$this, 'permission'],
            ]
        );

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/admin/bookings/(?P<id>\d+)/(?P<action>confirm|reject|cancel)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'act'],
                'permission_callback' => [$this, 'permission'],
                'args' => [
                    'id'     => ['type' => 'integer', 'required' => true],
                    'action' => ['type' => 'string', 'required' => true, 'enum' => ['confirm','reject','cancel']],
                ],
            ]
        );
    }

    public function permission(): bool|WP_Error
    {
        if (!current_user_can(Capabilities::MANAGE)) {
            return new WP_Error('tb_forbidden', __('Forbidden', 'trinity-booking'), ['status' => is_user_logged_in() ? 403 : 401]);
        }
        return true;
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $page    = (int) ($request['page']     ?? 1);
        $perPage = (int) ($request['per_page'] ?? 20);

        $filters = [
            'status'     => $request['status'] ? (string) $request['status'] : null,
            'service_id' => $request['service_id'] ? (int) $request['service_id'] : null,
            'from'       => $this->parseDate((string) ($request['from'] ?? '')),
            'to'         => $this->parseDate((string) ($request['to'] ?? '')),
        ];

        $result = $this->bookings->paginate($filters, $page, $perPage);
        $items  = array_map(static fn (Booking $b) => self::serialize($b), $result['items']);
        return new WP_REST_Response([
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    public function act(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request['id'];
        $action = (string) $request['action'];
        try {
            switch ($action) {
                case 'confirm':
                    $this->confirm->execute($id);
                    break;
                case 'reject':
                    $this->reject->execute($id);
                    break;
                case 'cancel':
                    $b = $this->bookings->findById($id);
                    if ($b === null) {
                        throw new BookingNotFound('not found');
                    }
                    $this->cancel->execute($b->publicUid());
                    break;
            }
        } catch (BookingNotFound $e) {
            return new WP_Error('tb_not_found', __('Booking not found.', 'trinity-booking'), ['status' => 404]);
        } catch (\DomainException $e) {
            return new WP_Error('tb_invalid_transition', $e->getMessage(), ['status' => 409]);
        }
        $refreshed = $this->bookings->findById($id);
        return new WP_REST_Response(self::serialize($refreshed));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(?Booking $b): array
    {
        if ($b === null) {
            return [];
        }
        return [
            'id'               => $b->id(),
            'public_uid'       => $b->publicUid(),
            'service_id'       => $b->serviceId(),
            'status'           => $b->status()->value,
            'starts_at_utc'    => $b->slot()->start->format(DATE_ATOM),
            'ends_at_utc'      => $b->slot()->end->format(DATE_ATOM),
            'timezone'         => $b->timezone(),
            'customer_name'    => $b->customerName(),
            'customer_email'   => $b->customerEmail(),
            'customer_phone'   => $b->customerPhone(),
            'customer_address' => $b->customerAddress(),
            'notes'            => $b->notes(),
            'created_at'       => $b->createdAt()->format(DATE_ATOM),
            'updated_at'       => $b->updatedAt()->format(DATE_ATOM),
        ];
    }

    private function parseDate(string $s): ?DateTimeImmutable
    {
        if ($s === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
