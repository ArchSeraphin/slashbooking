<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

use Closure;
use Trinity\Booking\Domain\Booking;

final class BookingExporter
{
    /**
     * @param Closure(string): list<Booking> $findByEmail
     */
    public function __construct(private readonly Closure $findByEmail)
    {
    }

    /**
     * @return array{data: list<array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}>, done: bool}
     */
    public function export(string $email, int $page): array
    {
        $bookings = ($this->findByEmail)($email);
        $data = [];
        foreach ($bookings as $b) {
            $data[] = [
                'group_id'    => 'trinity-booking',
                'group_label' => __('Réservations Trinity Booking', 'trinity-booking'),
                'item_id'     => (string) ($b->id() ?? 0),
                'data'        => [
                    ['name' => __('Nom', 'trinity-booking'),     'value' => $b->customerName()],
                    ['name' => __('E-mail', 'trinity-booking'),  'value' => $b->customerEmail()],
                    ['name' => __('Téléphone', 'trinity-booking'), 'value' => $b->customerPhone()],
                    ['name' => __('Adresse', 'trinity-booking'), 'value' => $b->customerAddress()],
                    ['name' => __('Notes', 'trinity-booking'),   'value' => $b->notes()],
                    ['name' => __('Statut', 'trinity-booking'),  'value' => $b->status()->value],
                    ['name' => __('Date du RDV', 'trinity-booking'), 'value' => $b->slot()->start->format('Y-m-d H:i')],
                    ['name' => __('Fuseau', 'trinity-booking'),  'value' => $b->timezone()],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }
}
