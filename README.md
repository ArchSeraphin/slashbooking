# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

- ✅ **Plan 1** — fondations + parcours de réservation public minimal fonctionnel.
- ✅ **Plan 2** — notifications e-mail (6 events + templates + .ics) + validation admin (HMAC e-mail + dashboard React).
- ⏸️ Plan 3 — Google OAuth + push WP → GCal.
- ⏸️ Plan 4 — webhook + pull GCal → WP.
- ⏸️ Plan 5 — éditeur de templates + RGPD + i18n + packaging.

Voir `docs/superpowers/specs/` pour la spécification complète et `docs/superpowers/plans/` pour les plans d'implémentation.

## Pré-requis

- PHP 8.1+
- WordPress 6.5+
- Composer (dev only — le ZIP de release inclut `vendor/`)

## Quickstart (état actuel post Plan 2)

1. Installer comme plugin WordPress standard (avec `vendor/` inclus dans le ZIP de release, ou `composer install --no-dev` en local).
2. Builder le dashboard admin : `npm install && npm run build` (génère `assets/dist/`).
3. Activer dans `/wp-admin/plugins.php`. L'activation crée les 6 tables (`wp_tb_*`), seed les services par défaut (`pv`, `irve`), installe les capabilities `trinity_booking_{manage,view}` et programme le cron quotidien J-1.
4. Sur une page, ajouter le shortcode :

   ```
   [trinity_booking service="pv"]
   [trinity_booking service="irve"]
   ```

5. Cycle complet maintenant fonctionnel :
   - Le visiteur prend RDV → 2 e-mails partent (client + admin avec boutons HMAC Confirmer/Refuser).
   - L'admin clique dans le mail OU passe par le menu **Trinity Booking** (dashboard React) pour confirmer/refuser/annuler.
   - À chaque transition, le client reçoit le mail correspondant (confirmé avec `.ics`, refusé, annulé).
   - Reminder J-1 envoyé automatiquement la veille à 10h.

> ⚠️ La sync Google Calendar (Plans 3-4) et l'éditeur de templates CodeMirror (Plan 5) ne sont pas encore livrés.

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

## Architecture (post Plan 2)

```
src/
├── Plugin.php              # Bootstrap + DI container léger
├── Activator.php           # Migrate + seed + caps + cron J-1
├── Deactivator.php         # Unschedule cron
├── Domain/                 # Entités pures (zéro dépendance WP)
│   ├── BookingStatus.php
│   ├── TimeSlot.php
│   ├── Service.php
│   ├── Booking.php             # confirm/reject idempotents
│   └── BusyBlock.php
├── Availability/
│   ├── SlotGenerator.php
│   └── AvailabilityCalculator.php
├── Booking/                    # Use cases (émettent des hooks WP)
│   ├── CreateBooking.php
│   ├── ConfirmBooking.php
│   ├── RejectBooking.php
│   ├── CancelBooking.php
│   ├── DecisionTokenSigner.php # HMAC SHA-256
│   └── Exceptions/
├── Notifications/              # E-mails + reminder + .ics
│   ├── TagRegistry.php
│   ├── TemplateRenderer.php
│   ├── TextBodyGenerator.php
│   ├── IcsBuilder.php          # RFC 5545
│   ├── DefaultTemplates.php    # 6 templates HTML bundlés
│   ├── MailDispatcher.php      # wp_mail multipart + .ics + fail-safe
│   ├── BookingNotifier.php     # Listener hooks → MailDispatcher
│   ├── ReminderScheduler.php   # Cron quotidien J-1
│   └── Events/
│       ├── EventKey.php        # Enum des 6 events
│       └── BookingContext.php  # DTO booking+service → tag dataset
├── Persistence/                # Seul layer touchant $wpdb
│   ├── Migrator.php
│   ├── ServiceRepository.php
│   ├── BookingRepository.php       # + paginate, findRemindersDue
│   ├── BusyBlockRepository.php
│   └── MailTemplateRepository.php  # Custom vs default bundled
├── Http/
│   ├── RestRouter.php
│   ├── UrlBuilder.php              # HMAC cancel/decision URLs
│   ├── PublicBookingController.php # /services, /availability, /bookings
│   ├── PublicCancelController.php  # /cancel (HMAC)
│   ├── DecisionController.php      # /decide?action=confirm|reject (HMAC)
│   └── AdminBookingController.php  # /admin/bookings + actions
├── Admin/
│   ├── Capabilities.php            # trinity_booking_manage/view
│   ├── AdminMenu.php               # Top-level menu WP
│   ├── Assets.php                  # Enqueue React bundle
│   └── react-app/                  # @wordpress/scripts SPA
│       └── src/
│           ├── index.jsx
│           ├── App.jsx
│           ├── BookingsPage.jsx
│           ├── BookingRow.jsx
│           ├── api.js
│           └── styles.scss
└── PublicFront/
    ├── Shortcode.php
    └── assets/                     # booking.js + booking.css
```

## Hooks WordPress émis

| Hook | Quand | Payload |
| --- | --- | --- |
| `trinity_booking/booking_created` | après création (`CreateBooking`) | `int $bookingId` |
| `trinity_booking/booking_confirmed` | après confirmation | `int $bookingId` |
| `trinity_booking/booking_rejected` | après refus | `int $bookingId` |
| `trinity_booking/booking_cancelled` | après annulation | `int $bookingId` |
| `trinity_booking/booking_reminder_due` | cron J-1 trouve un RDV éligible | `int $bookingId` |
| `tb_send_daily_reminders` | cron quotidien à 10h site TZ | — |

## Licence

GPL v2 or later
