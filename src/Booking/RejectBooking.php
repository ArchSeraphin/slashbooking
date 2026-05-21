<?php
declare(strict_types=1);

namespace Slash\Booking\Booking;

use Closure;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;

final class RejectBooking
{
    /**
     * @param Closure(int): ?Booking $find
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $find,
        private readonly Closure $persist,
    ) {
    }

    public function execute(int $bookingId): Booking
    {
        $booking = ($this->find)($bookingId);
        if ($booking === null) {
            throw new BookingNotFound("Booking {$bookingId} not found.");
        }
        $booking->reject();
        ($this->persist)($booking);

        if (function_exists('do_action') && $booking->id() !== null) {
            do_action('slashbooking/booking_rejected', $booking->id());
        }

        return $booking;
    }
}
