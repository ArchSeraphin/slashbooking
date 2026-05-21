<?php
declare(strict_types=1);

namespace Slash\Booking\Privacy;

use Closure;
use Slash\Booking\Domain\Booking;

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
                'group_id'    => 'slashbooking',
                'group_label' => __('Réservations SlashBooking', 'slashbooking'),
                'item_id'     => (string) ($b->id() ?? 0),
                'data'        => [
                    ['name' => __('Nom', 'slashbooking'),     'value' => $b->customerName()],
                    ['name' => __('E-mail', 'slashbooking'),  'value' => $b->customerEmail()],
                    ['name' => __('Téléphone', 'slashbooking'), 'value' => $b->customerPhone()],
                    ['name' => __('Adresse', 'slashbooking'), 'value' => $b->customerAddress()],
                    ['name' => __('Notes', 'slashbooking'),   'value' => $b->notes()],
                    ['name' => __('Statut', 'slashbooking'),  'value' => $b->status()->value],
                    ['name' => __('Date du RDV', 'slashbooking'), 'value' => $b->slot()->start->format('Y-m-d H:i')],
                    ['name' => __('Fuseau', 'slashbooking'),  'value' => $b->timezone()],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }
}
