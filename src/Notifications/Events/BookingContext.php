<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications\Events;

use DateTimeInterface;
use DateTimeZone;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;

/**
 * @phpstan-type Extra array{
 *   site_name?:string, site_url?:string, admin_email?:string,
 *   company_phone?:string, company_logo?:string,
 *   cancel_url?:string, confirm_url?:string, reject_url?:string, ics_url?:string,
 * }
 */
final class BookingContext
{
    /**
     * @param array<string, scalar|null> $data
     */
    private function __construct(public readonly array $data)
    {
    }

    /**
     * @param Extra $extra
     */
    public static function fromBooking(Booking $b, Service $svc, array $extra): self
    {
        $tz    = new DateTimeZone($b->timezone());
        $start = $b->slot()->start->setTimezone($tz);
        $end   = $b->slot()->end->setTimezone($tz);

        $data = [
            'customer_name'    => $b->customerName(),
            'customer_email'   => $b->customerEmail(),
            'customer_phone'   => $b->customerPhone(),
            'customer_address' => $b->customerAddress(),
            'service_name'     => $svc->name,
            'service_duration' => self::formatDuration($svc->durationMin),
            'appointment_date' => self::formatDate($start),
            'appointment_time' => $start->format('H:i'),
            'appointment_end'  => $end->format('H:i'),
            'timezone'         => $b->timezone(),
            'notes'            => $b->notes(),
            'cancel_url'       => (string) ($extra['cancel_url']  ?? ''),
            'confirm_url'      => (string) ($extra['confirm_url'] ?? ''),
            'reject_url'       => (string) ($extra['reject_url']  ?? ''),
            'ics_url'          => (string) ($extra['ics_url']     ?? ''),
            'site_name'        => (string) ($extra['site_name']   ?? ''),
            'site_url'         => (string) ($extra['site_url']    ?? ''),
            'admin_email'      => (string) ($extra['admin_email'] ?? ''),
            'company_logo'     => (string) ($extra['company_logo']  ?? ''),
            'company_phone'    => (string) ($extra['company_phone'] ?? ''),
        ];

        return new self($data);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . 'h' : sprintf('%dh%02d', $h, $m);
    }

    private static function formatDate(DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d');
    }
}
