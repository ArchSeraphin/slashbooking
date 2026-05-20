<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

use Closure;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Domain\Booking;

final class ConfirmBooking
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
        $booking->confirm();
        ($this->persist)($booking);
        return $booking;
    }
}
