<?php

declare(strict_types=1);

namespace Trinity\Booking\Booking;

use Closure;
use Trinity\Booking\Booking\Exceptions\InvalidBookingInput;
use Trinity\Booking\Booking\Exceptions\SlotUnavailable;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;

/**
 * @phpstan-type Command array{
 *   service: Service,
 *   slot: TimeSlot,
 *   timezone: string,
 *   customer_name: string,
 *   customer_email: string,
 *   customer_phone: string,
 *   customer_address: string,
 *   customer_meta: array<string, mixed>,
 *   notes: string,
 *   consent: bool,
 * }
 */
final class CreateBooking
{
    /**
     * @param Closure(Service, TimeSlot): bool $slotIsFree
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $slotIsFree,
        private readonly Closure $persist,
    ) {
    }

    /**
     * @param Command $cmd
     */
    public function execute(array $cmd): Booking
    {
        $this->validate($cmd);

        if (!($this->slotIsFree)($cmd['service'], $cmd['slot'])) {
            throw new SlotUnavailable('Slot is no longer available.');
        }

        $booking = Booking::createPending(
            serviceId: $cmd['service']->id ?? throw new \LogicException('Service must have an id.'),
            slot: $cmd['slot'],
            timezone: $cmd['timezone'],
            customerName: $cmd['customer_name'],
            customerEmail: $cmd['customer_email'],
            customerPhone: $cmd['customer_phone'],
            customerAddress: $cmd['customer_address'],
            customerMeta: $cmd['customer_meta'],
            notes: $cmd['notes'],
        );

        ($this->persist)($booking);

        if (function_exists('do_action') && $booking->id() !== null) {
            do_action('trinity_booking/booking_created', $booking->id());
        }

        return $booking;
    }

    /**
     * @param Command $cmd
     */
    private function validate(array $cmd): void
    {
        $errors = [];

        if (!$cmd['consent']) {
            $errors['consent'] = 'Consent is required.';
        }

        if (trim($cmd['customer_name']) === '') {
            $errors['customer_name'] = 'Name is required.';
        }

        if (!filter_var($cmd['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['customer_email'] = 'Valid email required.';
        }

        if (preg_replace('/\D+/', '', $cmd['customer_phone']) === '') {
            $errors['customer_phone'] = 'Phone required.';
        }

        if ($errors !== []) {
            throw new InvalidBookingInput($errors);
        }
    }
}
