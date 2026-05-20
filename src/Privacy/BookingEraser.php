<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

use Closure;

final class BookingEraser
{
    /**
     * @param Closure(string): int $anonymizeByEmail returns count of anonymized rows.
     */
    public function __construct(private readonly Closure $anonymizeByEmail)
    {
    }

    /**
     * @return array{items_removed:int, items_retained:int, messages:list<string>, done:bool}
     */
    public function erase(string $email, int $page): array
    {
        $count = ($this->anonymizeByEmail)($email);

        $messages = $count > 0
            ? [
                sprintf(
                    /* translators: %d: number of bookings anonymized */
                    __('Trinity Booking : %d réservation(s) anonymisée(s) (les agrégats sont conservés).', 'trinity-booking'),
                    $count
                ),
            ]
            : [];

        return [
            'items_removed'  => $count,
            'items_retained' => 0,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
