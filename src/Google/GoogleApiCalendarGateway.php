<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Google\Client as GoogleClient;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Channel as CalendarChannel;
use Google\Service\Calendar\Event as CalendarEvent;
use Google\Service\Calendar\Events as CalendarEvents;
use Google\Service\Exception as GoogleServiceException;
use Trinity\Booking\Google\Exceptions\GoogleApiError;
use Trinity\Booking\Google\Exceptions\GoogleClientError;
use Trinity\Booking\Google\Exceptions\SyncTokenExpired;

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

    public function listEvents(string $calendarId, ?string $syncToken = null, ?string $pageToken = null): array
    {
        try {
            /** @var CalendarEvents $resp */
            $resp = $this->service->events->listEvents($calendarId, array_filter([
                'syncToken'    => $syncToken,
                'pageToken'    => $pageToken,
                'singleEvents' => true,
                'showDeleted'  => true,
                'maxResults'   => 250,
            ], static fn ($v): bool => $v !== null));
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 410) {
                throw new SyncTokenExpired($e->getMessage());
            }
            $code = $e->getCode();
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google list events transient ({$code}): {$e->getMessage()}", $code);
            }
            throw new GoogleClientError("Google list events client error ({$code}): {$e->getMessage()}", $code);
        }

        $items = [];
        foreach ($resp->getItems() as $ev) {
            /** @var CalendarEvent $ev */
            $start = null;
            $end = null;
            $startObj = $ev->getStart();
            if ($startObj) {
                $start = $startObj->getDateTime() ?: $startObj->getDate();
            }
            $endObj = $ev->getEnd();
            if ($endObj) {
                $end = $endObj->getDateTime() ?: $endObj->getDate();
            }
            $items[] = [
                'id'      => (string) $ev->getId(),
                'status'  => (string) ($ev->getStatus() ?: 'confirmed'),
                'summary' => (string) ($ev->getSummary() ?: ''),
                'start'   => $start,
                'end'     => $end,
                'updated' => (string) $ev->getUpdated(),
                'etag'    => (string) $ev->getEtag(),
            ];
        }

        return [
            'items'         => $items,
            'nextPageToken' => $resp->getNextPageToken() ?: null,
            'nextSyncToken' => $resp->getNextSyncToken() ?: null,
        ];
    }

    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array {
        try {
            $channel = new CalendarChannel([
                'id'         => $channelId,
                'type'       => 'web_hook',
                'address'    => $address,
                'token'      => $token,
                'expiration' => (string) ((time() + $ttlSeconds) * 1000),
            ]);
            $created = $this->service->events->watch($calendarId, $channel);
            return [
                'channelId'  => (string) $created->getId(),
                'resourceId' => (string) $created->getResourceId(),
                'expiration' => (int) ((int) $created->getExpiration() / 1000),
            ];
        } catch (GoogleServiceException $e) {
            $code = $e->getCode();
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google watch transient ({$code}): {$e->getMessage()}", $code);
            }
            throw new GoogleClientError("Google watch client error ({$code}): {$e->getMessage()}", $code);
        }
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        try {
            $channel = new CalendarChannel(['id' => $channelId, 'resourceId' => $resourceId]);
            $this->service->channels->stop($channel);
        } catch (GoogleServiceException $e) {
            $code = $e->getCode();
            if ($code === 404 || $code === 410) {
                return; // Already gone — treat as success.
            }
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google stop channel transient ({$code}): {$e->getMessage()}", $code);
            }
            throw new GoogleClientError("Google stop channel client error ({$code}): {$e->getMessage()}", $code);
        }
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
