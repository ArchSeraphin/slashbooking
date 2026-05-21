<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

final class GoogleApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
