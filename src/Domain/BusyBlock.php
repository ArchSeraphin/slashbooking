<?php
declare(strict_types=1);

namespace Slash\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class BusyBlock
{
    public function __construct(
        public ?int $id,
        public string $source,
        public string $sourceId,
        public ?int $googleAccountId,
        public TimeSlot $slot,
        public string $summary,
        public ?DateTimeImmutable $lastSyncedAt = null,
    ) {
    }

    public static function fromGoogleEvent(
        int $googleAccountId,
        string $eventId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $summary,
        ?DateTimeImmutable $syncedAt = null,
    ): self {
        $utc = new DateTimeZone('UTC');
        return new self(
            id: null,
            source: 'google',
            sourceId: $eventId,
            googleAccountId: $googleAccountId,
            slot: new TimeSlot($start->setTimezone($utc), $end->setTimezone($utc)),
            summary: $summary,
            lastSyncedAt: $syncedAt?->setTimezone($utc) ?? new DateTimeImmutable('now', $utc),
        );
    }
}
