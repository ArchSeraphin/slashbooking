<?php
declare(strict_types=1);

namespace Slash\Booking\Privacy;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class BookingRetentionPurger
{
    public const HOOK = 'sb/purge_old_bookings';

    /**
     * @param Closure(DateTimeImmutable): int $deleteOlderThan returns count of deleted rows.
     */
    public function __construct(
        private readonly int $retentionDays,
        private readonly Closure $deleteOlderThan,
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function purge(): int
    {
        $cutoff = $this->now->sub(new DateInterval('P' . $this->retentionDays . 'D'));
        return ($this->deleteOlderThan)($cutoff);
    }

    public static function fromOptions(): self
    {
        $days = (int) (function_exists('get_option') ? get_option('sb_booking_retention_days', 1095) : 1095);
        if ($days < 30) {
            $days = 30; // safety floor
        }
        global $wpdb;
        $repo = new \Slash\Booking\Persistence\BookingRepository($wpdb);

        return new self(
            retentionDays: $days,
            deleteOlderThan: fn (DateTimeImmutable $cutoff) => $repo->deleteOlderThan($cutoff),
            now: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public static function register(): void
    {
        add_action(self::HOOK, static function (): void {
            self::fromOptions()->purge();
        });
    }
}
