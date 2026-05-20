# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

- ✅ **Plan 1** — fondations + parcours de réservation public minimal fonctionnel.
- ✅ **Plan 2** — notifications e-mail (6 events + templates + .ics) + validation admin (HMAC e-mail + dashboard React).
- ✅ **Plan 3** — Google OAuth + push WP → GCal via Action Scheduler (chiffrement sodium, journal de sync, `wp trinity-booking doctor`).
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

> ⚠️ La sync **entrante** Google Calendar (webhook, Plan 4) et l'éditeur de templates CodeMirror (Plan 5) ne sont pas encore livrés. Le push sortant WP → GCal est en place depuis le Plan 3.

## Google Calendar setup (Plan 3)

1. **Créer un projet sur Google Cloud Console** : https://console.cloud.google.com → APIs & Services → Library → activer **Google Calendar API**.
2. **OAuth consent screen** : configurer en mode "External", ajouter votre adresse e-mail comme *test user* (V1 mono-commercial — Google limite à 100 testeurs sans review).
3. **OAuth 2.0 Client ID** :
   - Type : *Web application*.
   - *Authorized redirect URI* : copier l'URI affichée dans **Trinity Booking → Google → Configuration OAuth** (typiquement `https://votresite.fr/wp-json/trinity-booking/v1/admin/google/oauth/callback`).
4. **Coller** le `Client ID` + `Client secret` dans le formulaire admin et **Enregistrer**.
5. Cliquer **Connecter mon Google Calendar** → autoriser sur l'écran Google → retour automatique sur la page admin avec `?connected=1`.
6. (Recommandé) Définir dans `wp-config.php` :

   ```php
   define('TRINITY_BOOKING_ENC_KEY', '<64-char hex string, ex: bin2hex(random_bytes(32))>');
   ```

   Cette constante est utilisée pour chiffrer les refresh tokens (sodium `crypto_secretbox`). Sinon une clé fallback est générée et stockée en option WP (un *admin notice* le rappelle).

À partir de là, chaque transition booking (création / confirmation / refus / annulation) déclenche un job Action Scheduler `tb/push_gcal_event` qui crée, met à jour ou supprime l'événement dans Google Calendar. Les erreurs 5xx sont retentées automatiquement ; les 4xx sont consignées dans le journal sans retry. `404`/`410` sur `delete` sont tolérés (déjà absent côté Google = succès).

## CLI diagnostics

```bash
wp trinity-booking doctor
```

Vérifie : compte connecté, rafraîchissement du token, accessibilité de la Calendar API (insère puis supprime un event de test). Utile en post-déploiement ou après une modification du Client ID / Secret.

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
| `tb/push_gcal_event` | Action Scheduler — push WP → GCal | `int $bookingId, string $action` (`create`/`confirm`/`delete`) |
| `tb_purge_sync_log` | cron quotidien à 3h site TZ | — |

## Licence

GPL v2 or later
