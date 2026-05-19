<?php
declare(strict_types=1);

namespace Trinity\Booking\Availability;

use Trinity\Booking\Domain\TimeSlot;

final class AvailabilityCalculator
{
    public function __construct(
        public readonly int $bufferBeforeMin,
        public readonly int $bufferAfterMin,
    ) {
    }

    /**
     * @param list<TimeSlot> $candidates
     * @param list<TimeSlot> $busy
     * @return list<TimeSlot>
     */
    public function filter(array $candidates, array $busy): array
    {
        if ($busy === []) {
            return array_values($candidates);
        }
        $free = [];
        foreach ($candidates as $slot) {
            $expanded = $slot->expand($this->bufferBeforeMin, $this->bufferAfterMin);
            $blocked = false;
            foreach ($busy as $b) {
                if ($expanded->overlaps($b)) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $free[] = $slot;
            }
        }
        return $free;
    }
}
