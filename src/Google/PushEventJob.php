<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Domain\Service;
use Slash\Booking\Google\Exceptions\GoogleApiError;
use Slash\Booking\Google\Exceptions\GoogleClientError;

final class PushEventJob
{
    /**
     * @param Closure(int): ?Booking         $findBooking
     * @param Closure(): ?GoogleAccount      $findAccount
     * @param Closure(Booking): void         $persistBooking
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly Closure $findBooking,
        private readonly Closure $findAccount,
        private readonly Closure $persistBooking,
        private readonly CalendarGateway $gateway,
        private readonly EventFormatter $formatter,
        private readonly Service $service,
        private readonly Closure $log,
    ) {
    }

    public function handle(int $bookingId, string $action): void
    {
        $booking = ($this->findBooking)($bookingId);
        if ($booking === null) {
            $this->logEntry($bookingId, $action, 'failed', null, 'Booking not found');
            return;
        }

        $account = ($this->findAccount)();
        if ($account === null) {
            $this->logEntry($bookingId, $action, 'failed', null, 'No Google account connected');
            return;
        }

        try {
            match ($action) {
                'create'  => $this->doCreate($booking, $account),
                'confirm' => $this->doConfirm($booking, $account),
                'delete'  => $this->doDelete($booking, $account),
                default   => $this->logEntry($bookingId, $action, 'failed', null, 'Unknown action'),
            };
        } catch (GoogleClientError $e) {
            // 4xx — deterministic. Log and swallow.
            $this->logEntry($bookingId, $action, 'failed', $booking->googleEventId(), $e->getMessage());
        } catch (GoogleApiError $e) {
            // 5xx / 429 — let Action Scheduler retry.
            $this->logEntry($bookingId, $action, 'retry', $booking->googleEventId(), $e->getMessage());
            throw $e;
        }
    }

    private function doCreate(Booking $booking, GoogleAccount $account): void
    {
        if ($booking->googleEventId() !== null) {
            $this->logEntry((int) $booking->id(), 'create', 'ok', $booking->googleEventId(), 'noop (already created)');
            return;
        }
        $payload = $this->formatter->format($booking, $this->service);
        $ref = $this->gateway->insertEvent($account->calendarId(), $payload);
        $booking->setGoogleEvent($ref['id'], $ref['etag']);
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'create', 'ok', $ref['id'], null);
    }

    private function doConfirm(Booking $booking, GoogleAccount $account): void
    {
        $payload = $this->formatter->format($booking, $this->service);
        $eventId = $booking->googleEventId();
        if ($eventId === null) {
            $ref = $this->gateway->insertEvent($account->calendarId(), $payload);
            $booking->setGoogleEvent($ref['id'], $ref['etag']);
            ($this->persistBooking)($booking);
            $this->logEntry((int) $booking->id(), 'create', 'ok', $ref['id'], 'fallback from confirm');
            return;
        }
        $ref = $this->gateway->patchEvent($account->calendarId(), $eventId, $payload);
        $booking->setGoogleEvent($ref['id'], $ref['etag']);
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'update', 'ok', $ref['id'], null);
    }

    private function doDelete(Booking $booking, GoogleAccount $account): void
    {
        $eventId = $booking->googleEventId();
        if ($eventId === null) {
            $this->logEntry((int) $booking->id(), 'delete', 'ok', null, 'noop (no event)');
            return;
        }
        try {
            $this->gateway->deleteEvent($account->calendarId(), $eventId);
        } catch (GoogleClientError $e) {
            if ($e->httpStatus !== 410 && $e->httpStatus !== 404) {
                throw $e;
            }
            // Already gone — treat as success.
        }
        $booking->clearGoogleEvent();
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'delete', 'ok', $eventId, null);
    }

    private function logEntry(int $bookingId, string $action, string $status, ?string $eventId, ?string $error): void
    {
        ($this->log)([
            'level'           => $status === 'failed' ? 'error' : ($status === 'retry' ? 'warn' : 'info'),
            'direction'       => 'wp_to_g',
            'entity'          => 'booking',
            'entity_id'       => $bookingId,
            'google_event_id' => $eventId,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
        ]);
    }
}
