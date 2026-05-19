# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

**Plan 1 terminé** — fondations + parcours de réservation public minimal fonctionnel.

Voir `docs/superpowers/specs/` pour la spécification complète et `docs/superpowers/plans/` pour les plans d'implémentation (Plan 2 : notifications + admin · Plan 3 : Google OAuth + push WP→GCal · Plan 4 : webhook + pull GCal→WP · Plan 5 : éditeur de templates + RGPD + packaging).

## Pré-requis

- PHP 8.1+
- WordPress 6.5+
- Composer (dev only — le ZIP de release inclut `vendor/`)

## Quickstart (V1 minimal — état actuel)

1. Installer comme plugin WordPress standard (avec `vendor/` inclus dans le ZIP de release, ou `composer install --no-dev` en local).
2. Activer dans `/wp-admin/plugins.php`. L'activation crée les 6 tables (`wp_tb_*`) et seed les deux services par défaut (`pv`, `irve`).
3. Sur une page, ajouter le shortcode :

   ```
   [trinity_booking service="pv"]
   [trinity_booking service="irve"]
   ```

4. Un visiteur peut maintenant choisir une date, sélectionner un créneau libre, remplir ses informations et envoyer une demande. Le RDV est enregistré en base avec statut `pending`.

> ⚠️ Le Plan 1 **ne couvre pas encore** : envoi d'e-mails, validation admin (boutons 1-clic ou dashboard), sync Google Calendar, dashboard React. Ces fonctionnalités viennent dans les plans suivants.

## Installation (dev)

```bash
composer install
```

## Tests

```bash
composer test                # unit (Brain Monkey, pas de WP)
composer test:integration    # nécessite la WP test suite (skip propre sinon)
composer stan                # PHPStan niveau 8
composer cs                  # PHPCS (PSR-12 + règles WP sécurité/i18n)
```

### Installer la WP test suite (optionnel)

Pour faire tourner les tests d'intégration localement :

```bash
# Adapter db_user/db_pass à votre MySQL local
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Les tests d'intégration vérifient le Migrator, les Repositories, les controllers REST et le parcours end-to-end complet.

## Architecture (Plan 1)

```
src/
├── Plugin.php              # Bootstrap + DI container léger
├── Activator.php           # Migrate + seed + generate HMAC secret
├── Deactivator.php
├── Domain/                 # Entités pures (zéro dépendance WP)
│   ├── BookingStatus.php
│   ├── TimeSlot.php
│   ├── Service.php
│   ├── Booking.php
│   └── BusyBlock.php
├── Availability/
│   ├── SlotGenerator.php       # Génère créneaux candidats
│   └── AvailabilityCalculator.php  # Filtre selon buffers + busy
├── Booking/
│   ├── CreateBooking.php       # Use case
│   ├── CancelBooking.php
│   ├── DecisionTokenSigner.php # HMAC SHA-256
│   └── Exceptions/
├── Persistence/                # Seul layer touchant $wpdb
│   ├── Migrator.php
│   ├── ServiceRepository.php
│   ├── BookingRepository.php
│   └── BusyBlockRepository.php
├── Http/                       # Controllers REST
│   ├── RestRouter.php
│   ├── PublicBookingController.php  # /services, /availability, /bookings
│   └── PublicCancelController.php   # /cancel (HMAC)
└── PublicFront/
    ├── Shortcode.php
    └── assets/                 # booking.js + booking.css (vanilla)
```

## Licence

GPL v2 or later
