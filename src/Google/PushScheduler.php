<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Closure;

final class PushScheduler
{
    public const HOOK = 'tb/push_gcal_event';

    /** @var Closure(string, array<int, mixed>): void */
    private Closure $enqueue;

    /**
     * @param (Closure(string, array<int, mixed>): void)|null $enqueue
     */
    public function __construct(?Closure $enqueue = null)
    {
        $this->enqueue = $enqueue ?? self::defaultEnqueue();
    }

    public function register(): void
    {
        add_action('trinity_booking/booking_created',   [$this, 'onCreated'],   20, 1);
        add_action('trinity_booking/booking_confirmed', [$this, 'onConfirmed'], 20, 1);
        add_action('trinity_booking/booking_rejected',  [$this, 'onRejected'],  20, 1);
        add_action('trinity_booking/booking_cancelled', [$this, 'onCancelled'], 20, 1);
    }

    public function onCreated(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'create']);
    }

    public function onConfirmed(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'confirm']);
    }

    public function onRejected(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'delete']);
    }

    public function onCancelled(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'delete']);
    }

    /**
     * @return Closure(string, array<int, mixed>): void
     */
    private static function defaultEnqueue(): Closure
    {
        return static function (string $hook, array $args): void {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action($hook, $args, 'trinity-booking');
                return;
            }
            // Fallback synchronous (Action Scheduler not loaded — should not happen in production).
            if ($hook !== '' && function_exists('do_action')) {
                do_action($hook, ...$args);
            }
        };
    }
}
