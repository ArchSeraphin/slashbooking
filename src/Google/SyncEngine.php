<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\SyncTokenExpired;

final class SyncEngine
{
    /**
     * @param Closure(string): ?int               $findBookingByEventId  Return booking id if our reflection, else null
     * @param Closure(BusyBlock): void            $upsertBusyBlock
     * @param Closure(int, string): void          $deleteBusyBlock       (googleAccountId, sourceId)
     * @param Closure(GoogleAccount): void        $persistAccount
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly Closure $findBookingByEventId,
        private readonly Closure $upsertBusyBlock,
        private readonly Closure $deleteBusyBlock,
        private readonly Closure $persistAccount,
        private readonly Closure $log,
    ) {
    }

    public function pull(GoogleAccount $account, CalendarGateway $gateway): PullResult
    {
        $result     = new PullResult();
        $accountId  = (int) $account->id();
        $calendarId = $account->calendarId();
        $syncToken  = $account->syncToken();
        $pageToken  = null;
        $isFullSync = $syncToken === null;
        $page       = ['items' => [], 'nextPageToken' => null, 'nextSyncToken' => null];

        try {
            do {
                $page = $gateway->listEvents($calendarId, $syncToken, $pageToken);
                $this->ingestPage($page, $accountId, $result);
                $syncToken = null;
                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== null);

            if ($page['nextSyncToken'] !== null) {
                $account->updateSyncToken($page['nextSyncToken']);
            }
        } catch (SyncTokenExpired $e) {
            $account->clearSyncToken();
            $this->logEntry('warn', $accountId, 'sync_token_expired', 'retry', $e->getMessage());

            $pageToken  = null;
            $isFullSync = true;
            do {
                $page = $gateway->listEvents($calendarId, null, $pageToken);
                $this->ingestPage($page, $accountId, $result);
                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== null);

            if ($page['nextSyncToken'] !== null) {
                $account->updateSyncToken($page['nextSyncToken']);
            }
        }

        if ($isFullSync) {
            $account->markFullSyncedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
        ($this->persistAccount)($account);

        $this->logEntry(
            level: 'info',
            accountId: $accountId,
            action: 'pull',
            status: 'ok',
            error: null,
            extra: [
                'upserted'          => $result->upserted,
                'deleted'           => $result->deleted,
                'ignoredReflection' => $result->ignoredReflection,
                'fullSync'          => $isFullSync,
            ],
        );

        return $result;
    }

    /**
     * @param array{items: list<array<string, mixed>>, nextPageToken: ?string, nextSyncToken: ?string} $page
     */
    private function ingestPage(array $page, int $accountId, PullResult $result): void
    {
        $utc = new DateTimeZone('UTC');
        foreach ($page['items'] as $ev) {
            $eventId = (string) $ev['id'];
            $status  = (string) ($ev['status'] ?? 'confirmed');

            if ($status === 'cancelled') {
                ($this->deleteBusyBlock)($accountId, $eventId);
                $result->deleted++;
                continue;
            }

            $bookingId = ($this->findBookingByEventId)($eventId);
            if ($bookingId !== null) {
                $result->ignoredReflection++;
                continue;
            }

            $startStr = $ev['start'] ?? null;
            $endStr   = $ev['end']   ?? null;
            if (!is_string($startStr) || !is_string($endStr)) {
                $this->logEntry('warn', $accountId, 'pull_skip_no_datetime', 'failed', 'Event without dateTime', ['eventId' => $eventId]);
                continue;
            }

            $bb = BusyBlock::fromGoogleEvent(
                googleAccountId: $accountId,
                eventId: $eventId,
                start: new DateTimeImmutable($startStr),
                end: new DateTimeImmutable($endStr),
                summary: (string) ($ev['summary'] ?? ''),
                syncedAt: new DateTimeImmutable('now', $utc),
            );
            ($this->upsertBusyBlock)($bb);
            $result->upserted++;
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logEntry(string $level, int $accountId, string $action, string $status, ?string $error, array $extra = []): void
    {
        ($this->log)([
            'level'           => $level,
            'direction'       => 'g_to_wp',
            'entity'          => 'busy_block',
            'entity_id'       => $accountId,
            'google_event_id' => null,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
            'payload'         => $extra,
        ]);
    }
}
