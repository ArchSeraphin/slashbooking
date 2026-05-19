# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

En développement actif — voir `docs/superpowers/specs/` et `docs/superpowers/plans/`.

## Pré-requis

- PHP 8.1+
- WordPress 6.5+
- Composer (dev only — le ZIP de release inclut `vendor/`)

## Installation (dev)

```bash
composer install
```

## Tests

```bash
composer test           # unit
composer test:integration   # nécessite WP test suite
composer stan           # PHPStan niveau 8
composer cs             # PHPCS
```

## Licence

GPL v2 or later
