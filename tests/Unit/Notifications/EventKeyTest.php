<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\Events\EventKey;

final class EventKeyTest extends TestCase
{
    public function test_lists_six_event_keys(): void
    {
        $keys = array_map(static fn (EventKey $e) => $e->value, EventKey::cases());
        self::assertSame([
            'booking.pending.client',
            'booking.pending.admin',
            'booking.confirmed.client',
            'booking.rejected.client',
            'booking.cancelled.client',
            'booking.reminder.client',
        ], $keys);
    }

    public function test_recipient_returns_admin_or_client(): void
    {
        self::assertSame('client', EventKey::PENDING_CLIENT->recipient());
        self::assertSame('admin', EventKey::PENDING_ADMIN->recipient());
        self::assertSame('client', EventKey::CONFIRMED_CLIENT->recipient());
        self::assertSame('client', EventKey::REJECTED_CLIENT->recipient());
        self::assertSame('client', EventKey::CANCELLED_CLIENT->recipient());
        self::assertSame('client', EventKey::REMINDER_CLIENT->recipient());
    }
}
