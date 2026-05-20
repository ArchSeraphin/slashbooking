<?php
declare(strict_types=1);

namespace Trinity\Booking\Notifications\Events;

enum EventKey: string
{
    case PENDING_CLIENT   = 'booking.pending.client';
    case PENDING_ADMIN    = 'booking.pending.admin';
    case CONFIRMED_CLIENT = 'booking.confirmed.client';
    case REJECTED_CLIENT  = 'booking.rejected.client';
    case CANCELLED_CLIENT = 'booking.cancelled.client';
    case REMINDER_CLIENT  = 'booking.reminder.client';

    public function recipient(): string
    {
        return $this === self::PENDING_ADMIN ? 'admin' : 'client';
    }
}
