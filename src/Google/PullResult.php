<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

final class PullResult
{
    public int $upserted = 0;
    public int $deleted = 0;
    public int $ignoredReflection = 0;
}
