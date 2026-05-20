<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Support;

use Trinity\Booking\Google\CalendarGateway;
use Trinity\Booking\Google\Exceptions\GoogleApiError;
use Trinity\Booking\Google\Exceptions\GoogleClientError;

final class FakeCalendarGateway implements CalendarGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $events = [];

    /** @var list<array{op:string, calendar:string, payload:mixed, eventId?:string}> */
    public array $calls = [];

    private int $seq = 0;

    public bool $failNext = false;

    public ?int $throwClientErrorOnDelete = null;

    public function insertEvent(string $calendarId, array $payload): array
    {
        $this->calls[] = ['op' => 'insert', 'calendar' => $calendarId, 'payload' => $payload];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        $id = 'evt_' . (++$this->seq);
        $etag = '"etag_' . $this->seq . '"';
        $this->events[$id] = ['etag' => $etag, 'payload' => $payload];
        return ['id' => $id, 'etag' => $etag];
    }

    public function patchEvent(string $calendarId, string $eventId, array $payload): array
    {
        $this->calls[] = ['op' => 'patch', 'calendar' => $calendarId, 'eventId' => $eventId, 'payload' => $payload];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        if (!isset($this->events[$eventId])) {
            throw new GoogleClientError('Event not found', 404);
        }
        $this->events[$eventId]['payload'] = $payload;
        $this->events[$eventId]['etag'] = '"etag_patched_' . (++$this->seq) . '"';
        return ['id' => $eventId, 'etag' => $this->events[$eventId]['etag']];
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->calls[] = ['op' => 'delete', 'calendar' => $calendarId, 'eventId' => $eventId];
        if ($this->throwClientErrorOnDelete !== null) {
            $code = $this->throwClientErrorOnDelete;
            $this->throwClientErrorOnDelete = null;
            throw new GoogleClientError("Simulated {$code}", $code);
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        unset($this->events[$eventId]);
    }
}
