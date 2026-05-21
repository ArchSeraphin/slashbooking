<?php
declare(strict_types=1);

namespace Slash\Booking\Domain;

enum BookingStatus: string
{
    case PENDING   = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED  = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function blocksSlot(): bool
    {
        // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- false positive: $this is valid inside an enum method (PHP 8.1+).
        return match ($this) {
            self::PENDING, self::CONFIRMED => true,
            self::REJECTED, self::CANCELLED, self::COMPLETED => false,
        };
    }
}
