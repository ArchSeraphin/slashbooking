<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Support;

use Slash\Booking\Google\CalendarGateway;
use Slash\Booking\Google\Exceptions\GoogleApiError;
use Slash\Booking\Google\Exceptions\GoogleClientError;
use Slash\Booking\Google\Exceptions\SyncTokenExpired;

final class FakeCalendarGateway implements CalendarGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $events = [];

    /** @var list<array{op:string, calendar?:string, payload?:mixed, eventId?:string, syncToken?:?string, pageToken?:?string, channelId?:string, resourceId?:string}> */
    public array $calls = [];

    private int $seq = 0;

    public bool $failNext = false;

    public ?int $throwClientErrorOnDelete = null;

    public bool $throwSyncTokenExpiredNext = false;

    /** @var list<array{items: list<array<string, mixed>>, nextPageToken: ?string, nextSyncToken: ?string}> */
    public array $pages = [];

    /** @var list<array{channelId:string, resourceId:string}> */
    public array $stoppedChannels = [];

    /** @var list<array{calendarId:string, channelId:string, address:string, token:string, ttl:int}> */
    public array $startedChannels = [];

    /** @var list<array{id:string, summary:string, primary:bool, accessRole:string, timeZone:string, backgroundColor:?string}> */
    public array $calendars = [];

    public function listCalendars(): array
    {
        $this->calls[] = ['op' => 'listCalendars'];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        return $this->calendars;
    }

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
        return ['id' => $eventId, 'etag' => (string) $this->events[$eventId]['etag']];
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

    public function listEvents(string $calendarId, ?string $syncToken = null, ?string $pageToken = null): array
    {
        $this->calls[] = [
            'op'        => 'list',
            'calendar'  => $calendarId,
            'syncToken' => $syncToken,
            'pageToken' => $pageToken,
        ];
        if ($this->throwSyncTokenExpiredNext) {
            $this->throwSyncTokenExpiredNext = false;
            throw new SyncTokenExpired('Simulated 410');
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        $page = array_shift($this->pages);
        if ($page === null) {
            return ['items' => [], 'nextPageToken' => null, 'nextSyncToken' => 'sync_' . (++$this->seq)];
        }
        return $page;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function queuePage(array $items, ?string $nextPageToken = null, ?string $nextSyncToken = null): void
    {
        $this->pages[] = [
            'items'         => $items,
            'nextPageToken' => $nextPageToken,
            'nextSyncToken' => $nextSyncToken,
        ];
    }

    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array {
        $this->startedChannels[] = [
            'calendarId' => $calendarId,
            'channelId'  => $channelId,
            'address'    => $address,
            'token'      => $token,
            'ttl'        => $ttlSeconds,
        ];
        $this->calls[] = ['op' => 'watch', 'calendar' => $calendarId, 'channelId' => $channelId];
        return [
            'channelId'  => $channelId,
            'resourceId' => 'res_' . $channelId,
            'expiration' => time() + $ttlSeconds,
        ];
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        $this->stoppedChannels[] = ['channelId' => $channelId, 'resourceId' => $resourceId];
        $this->calls[] = ['op' => 'stop', 'channelId' => $channelId, 'resourceId' => $resourceId];
    }
}
