<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use DateTimeZone;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\Service;

final class EventFormatter
{
    public const PENDING_PREFIX = '[À VALIDER] ';

    public function __construct(
        private readonly string $pendingColorId,
        private readonly string $confirmedColorId,
    ) {
    }

    /**
     * @return array{
     *   summary: string,
     *   description: string,
     *   start: array{dateTime: string, timeZone: string},
     *   end: array{dateTime: string, timeZone: string},
     *   colorId: string
     * }
     */
    public function format(Booking $booking, Service $service): array
    {
        $isPending = $booking->status() === BookingStatus::PENDING;
        $prefix    = $isPending ? self::PENDING_PREFIX : '';
        $summary   = $prefix . $service->name . ' · ' . $booking->customerName();

        $tz    = $booking->timezone();
        $zone  = new DateTimeZone($tz);
        $start = $booking->slot()->start->setTimezone($zone);
        $end   = $booking->slot()->end->setTimezone($zone);

        return [
            'summary'     => $summary,
            'description' => $this->description($booking),
            'start'       => [
                'dateTime' => $start->format('Y-m-d\TH:i:sP'),
                'timeZone' => $tz,
            ],
            'end'         => [
                'dateTime' => $end->format('Y-m-d\TH:i:sP'),
                'timeZone' => $tz,
            ],
            'colorId'     => $isPending ? $this->pendingColorId : $this->confirmedColorId,
        ];
    }

    private function description(Booking $b): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [
            'Client : ' . $esc($b->customerName()),
            'E-mail : ' . $esc($b->customerEmail()),
            'Téléphone : ' . $esc($b->customerPhone()),
        ];
        if ($b->customerAddress() !== '') {
            $lines[] = 'Adresse : ' . $esc($b->customerAddress());
        }
        if ($b->notes() !== '') {
            $lines[] = '';
            $lines[] = 'Notes : ' . $esc($b->notes());
        }
        return implode("\n", $lines);
    }
}
