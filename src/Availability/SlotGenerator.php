<?php
declare(strict_types=1);

namespace Slash\Booking\Availability;

use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use DatePeriod;

final class SlotGenerator
{
    private DateTimeImmutable $now;

    public function __construct(
        public readonly int $stepMinutes,
        public readonly string $siteTimezone,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return list<TimeSlot> en UTC, triés croissants
     */
    public function generate(Service $service, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tz = new DateTimeZone($this->siteTimezone);
        $utc = new DateTimeZone('UTC');
        $minStartUtc = $this->now->modify('+' . $service->minLeadTimeHours . ' hours');
        $maxStartUtc = $this->now->modify('+' . $service->maxHorizonDays . ' days');

        $fromLocal = $from->setTimezone($tz);
        $toLocal   = $to->setTimezone($tz);

        $oneDay = new DateInterval('P1D');
        $days = new DatePeriod(
            $fromLocal->setTime(0, 0),
            $oneDay,
            $toLocal->setTime(0, 0),
        );

        $slots = [];
        foreach ($days as $day) {
            /** @var DateTimeImmutable $day */
            $isoDay = (int) $day->format('N');
            foreach ($service->weeklyHoursForIsoDay($isoDay) as $window) {
                [$oh, $om] = array_map('intval', explode(':', $window['open']));
                [$ch, $cm] = array_map('intval', explode(':', $window['close']));
                $windowOpen  = $day->setTime($oh, $om);
                $windowClose = $day->setTime($ch, $cm);

                $cursor = $windowOpen;
                while (true) {
                    $startLocal = $cursor;
                    $endLocal   = $cursor->modify('+' . $service->durationMin . ' minutes');
                    if ($endLocal > $windowClose) {
                        break;
                    }
                    $startUtc = $startLocal->setTimezone($utc);
                    $endUtc   = $endLocal->setTimezone($utc);

                    if ($startUtc >= $minStartUtc && $startUtc <= $maxStartUtc) {
                        $slots[] = new TimeSlot($startUtc, $endUtc);
                    }
                    $cursor = $cursor->modify('+' . $this->stepMinutes . ' minutes');
                }
            }
        }

        usort($slots, static fn (TimeSlot $a, TimeSlot $b) => $a->start <=> $b->start);
        return $slots;
    }
}
