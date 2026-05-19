<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

final readonly class BusyBlock
{
    public function __construct(
        public ?int $id,
        public string $source,
        public string $sourceId,
        public ?int $googleAccountId,
        public TimeSlot $slot,
        public string $summary,
    ) {
    }
}
