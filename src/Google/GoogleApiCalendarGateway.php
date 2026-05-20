<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Google\Client as GoogleClient;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Event as CalendarEvent;
use Google\Service\Exception as GoogleServiceException;
use Trinity\Booking\Google\Exceptions\GoogleApiError;
use Trinity\Booking\Google\Exceptions\GoogleClientError;

final class GoogleApiCalendarGateway implements CalendarGateway
{
    private CalendarService $service;

    public function __construct(GoogleClient $client)
    {
        $this->service = new CalendarService($client);
    }

    public function insertEvent(string $calendarId, array $payload): array
    {
        return $this->call(function () use ($calendarId, $payload): array {
            $event = new CalendarEvent($payload);
            $created = $this->service->events->insert($calendarId, $event);
            return ['id' => (string) $created->getId(), 'etag' => (string) $created->getEtag()];
        });
    }

    public function patchEvent(string $calendarId, string $eventId, array $payload): array
    {
        return $this->call(function () use ($calendarId, $eventId, $payload): array {
            $event = new CalendarEvent($payload);
            $updated = $this->service->events->patch($calendarId, $eventId, $event);
            return ['id' => (string) $updated->getId(), 'etag' => (string) $updated->getEtag()];
        });
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->call(function () use ($calendarId, $eventId): array {
            $this->service->events->delete($calendarId, $eventId);
            return ['id' => $eventId, 'etag' => ''];
        });
    }

    /**
     * @param callable(): array{id: string, etag: string} $fn
     * @return array{id: string, etag: string}
     */
    private function call(callable $fn): array
    {
        try {
            return $fn();
        } catch (GoogleServiceException $e) {
            $code = $e->getCode();
            $msg  = $e->getMessage();
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google API transient error ({$code}): {$msg}", $code);
            }
            throw new GoogleClientError("Google API client error ({$code}): {$msg}", $code);
        } catch (\Throwable $e) {
            throw new GoogleApiError('Unexpected Google API error: ' . $e->getMessage());
        }
    }
}
