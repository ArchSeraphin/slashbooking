<?php
declare(strict_types=1);

namespace Slash\Booking\Booking\Exceptions;

final class InvalidBookingInput extends \DomainException
{
    /**
     * @param array<string, string> $errors  field → message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid booking input.');
    }
}
