<?php
declare(strict_types=1);

namespace Trinity\Booking\Google\Exceptions;

/**
 * Marker exception : 410 Gone on events.list — server told us the syncToken is too old / invalid.
 * Caller MUST reset sync_token and retry a full sync.
 */
final class SyncTokenExpired extends \RuntimeException
{
    public function __construct(string $message = 'Sync token expired (HTTP 410)', public readonly int $httpStatus = 410)
    {
        parent::__construct($message);
    }
}
