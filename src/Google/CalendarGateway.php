<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

/**
 * @phpstan-type EventPayload array{
 *   summary: string,
 *   description?: string,
 *   start: array{dateTime: string, timeZone: string},
 *   end: array{dateTime: string, timeZone: string},
 *   colorId?: string,
 *   attendees?: list<array{email: string}>
 * }
 * @phpstan-type EventRef array{id: string, etag: string}
 * @phpstan-type RemoteEvent array{
 *   id: string,
 *   status: string,
 *   summary: string,
 *   start: ?string,
 *   end: ?string,
 *   updated: string,
 *   etag: string
 * }
 * @phpstan-type EventsPage array{
 *   items: list<RemoteEvent>,
 *   nextPageToken: ?string,
 *   nextSyncToken: ?string
 * }
 * @phpstan-type WatchChannelRef array{
 *   channelId: string,
 *   resourceId: string,
 *   expiration: int
 * }
 */
interface CalendarGateway
{
    /**
     * @param EventPayload $payload
     * @return EventRef
     */
    public function insertEvent(string $calendarId, array $payload): array;

    /**
     * @param EventPayload $payload
     * @return EventRef
     */
    public function patchEvent(string $calendarId, string $eventId, array $payload): array;

    public function deleteEvent(string $calendarId, string $eventId): void;

    /**
     * List events using either a syncToken (incremental) or a pageToken (full sync continuation).
     * If both are null → fresh full sync.
     *
     * @return EventsPage
     */
    public function listEvents(
        string $calendarId,
        ?string $syncToken = null,
        ?string $pageToken = null
    ): array;

    /**
     * Create a push notification channel for the calendar.
     *
     * @return WatchChannelRef
     */
    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array;

    /**
     * Stop an existing push notification channel.
     */
    public function stopChannel(string $channelId, string $resourceId): void;
}
