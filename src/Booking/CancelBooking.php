<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Domain\Booking;
use Closure;

final class CancelBooking
{
    /**
     * @param Closure(string): ?Booking $find
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $find,
        private readonly Closure $persist,
    ) {
    }

    public function execute(string $publicUid): Booking
    {
        $booking = ($this->find)($publicUid);
        if ($booking === null) {
            throw new BookingNotFound('Booking not found.');
        }
        $booking->cancel();
        ($this->persist)($booking);

        if (function_exists('do_action') && $booking->id() !== null) {
            do_action('trinity_booking/booking_cancelled', $booking->id());
        }

        return $booking;
    }
}
