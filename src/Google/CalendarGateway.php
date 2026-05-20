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
}
