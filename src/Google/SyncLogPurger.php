<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class SyncLogPurger
{
    public const HOOK = 'sb_purge_sync_log';
    public const RETENTION_DAYS = 30;

    /** @var Closure(DateTimeImmutable): int */
    private Closure $purge;

    /**
     * @param Closure(DateTimeImmutable): int $purge
     */
    public function __construct(Closure $purge)
    {
        $this->purge = $purge;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runOnCron']);
    }

    public function runOnCron(): void
    {
        $this->run(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function run(DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-' . self::RETENTION_DAYS . ' days');
        return ($this->purge)($cutoff);
    }
}
