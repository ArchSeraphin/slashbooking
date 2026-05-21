<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

/**
 * @phpstan-type Tag array{name:string, category:string, description:string, raw:bool}
 */
final class TagRegistry
{
    private const RAW_TAGS = ['cancel_url', 'confirm_url', 'reject_url', 'ics_url', 'company_logo'];

    /** @var array<string, Tag> */
    private array $tags;

    public function __construct()
    {
        $this->tags = $this->buildTags();
    }

    /**
     * @return Tag|null
     */
    public function find(string $name): ?array
    {
        return $this->tags[$name] ?? null;
    }

    /**
     * @return array<string, list<Tag>>
     */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->tags as $tag) {
            $out[$tag['category']][] = $tag;
        }
        return $out;
    }

    /**
     * @return array<string, Tag>
     */
    private function buildTags(): array
    {
        $defs = [
            ['customer', 'customer_name',    'Nom du client'],
            ['customer', 'customer_email',   'E-mail du client'],
            ['customer', 'customer_phone',   'Téléphone du client'],
            ['customer', 'customer_address', 'Adresse du client'],
            ['appointment', 'service_name',     'Nom du service'],
            ['appointment', 'service_duration', 'Durée du service'],
            ['appointment', 'appointment_date', 'Date du RDV (long, locale)'],
            ['appointment', 'appointment_time', 'Heure de début (HH:mm)'],
            ['appointment', 'appointment_end',  'Heure de fin (HH:mm)'],
            ['appointment', 'timezone',         'Fuseau horaire'],
            ['appointment', 'notes',            'Notes du client'],
            ['actions', 'cancel_url',  "URL d'annulation client"],
            ['actions', 'confirm_url', 'URL de confirmation admin'],
            ['actions', 'reject_url',  'URL de refus admin'],
            ['actions', 'ics_url',     'URL téléchargement .ics'],
            ['site', 'site_name',     'Nom du site'],
            ['site', 'site_url',      'URL du site'],
            ['site', 'admin_email',   'E-mail admin'],
            ['site', 'company_logo',  'Balise <img> du logo (option plugin)'],
            ['site', 'company_phone', 'Téléphone société (option plugin)'],
        ];
        $out = [];
        foreach ($defs as [$cat, $name, $desc]) {
            $out[$name] = [
                'name'        => $name,
                'category'    => $cat,
                'description' => $desc,
                'raw'         => in_array($name, self::RAW_TAGS, true),
            ];
        }
        return $out;
    }
}
