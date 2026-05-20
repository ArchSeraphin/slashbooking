# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

**Version courante : 1.0.0** — V1 stable, prête à déployer en production.

- ✅ **Plan 1** — fondations + parcours de réservation public minimal fonctionnel.
- ✅ **Plan 2** — notifications e-mail (6 events + templates + .ics) + validation admin (HMAC e-mail + dashboard React).
- ✅ **Plan 3** — Google OAuth + push WP → GCal via Action Scheduler.
- ✅ **Plan 4** — webhook + pull GCal → WP (SyncEngine + WatchChannelManager + crons).
- ✅ **Plan 5** — éditeur de templates CodeMirror + RGPD + i18n + packaging.

Voir `CHANGELOG.md` pour le détail.

Voir `docs/superpowers/specs/` pour la spécification complète et `docs/superpowers/plans/` pour les plans d'implémentation.

## Pré-requis

- PHP 8.1+
- WordPress 6.5+
- Composer (dev only — le ZIP de release inclut `vendor/`)

## Installation production (ZIP)

1. Télécharger `trinity-booking-1.0.0.zip` depuis la page Releases (ou le construire via `bin/build-release.sh`).
2. **WP Admin → Extensions → Ajouter → Téléverser** → uploader le ZIP → activer.
3. L'activation crée 6 tables `wp_tb_*`, seed `pv` + `irve`, installe les capabilities, programme les crons (J-1 reminder à 10h, sync log purge à 3h, watch renewal à 4h, retention purge le 1er du mois à 3h30).
4. Définir dans `wp-config.php` (recommandé) :

   ```php
   define( 'TRINITY_BOOKING_ENC_KEY', '<64-char hex string>' );
   ```

   Génère via : `php -r 'echo bin2hex(random_bytes(32));'`.

5. Configurer Google Calendar (section ci-dessous).
6. Insérer le shortcode dans une page :

   ```
   [trinity_booking service="pv"]
   ```

7. (Optionnel) Définir la page "Mentions légales" : `wp option update tb_legal_page_id <page_id>`.

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

## Sync entrante Google → WP (Plan 4)

Un événement créé directement dans Google Calendar devient automatiquement un `BusyBlock` côté WP en ~5 secondes (via webhook) ou ≤ 15 minutes (cron fallback).

### Mécanisme

1. **Watch channel.** Une fois OAuth connecté, ouvrir **Trinity Booking → Google** et cliquer **Démarrer le watch**. Un channel push notifications est créé chez Google (TTL 7 jours). À chaque modif de calendrier, Google POST notre webhook public.
2. **Webhook.** `POST /wp-json/trinity-booking/v1/google/webhook`. Vérification HMAC du header `X-Goog-Channel-Token` contre le secret persisté → enfile un job `tb/google_pull` (debounce 5 s).
3. **Pull job.** Action Scheduler exécute `tb/google_pull` qui appelle `events.list?syncToken=…` pour récupérer les diffs incrémentaux. Upsert / delete des `BusyBlock` selon `status` de l'event (`confirmed`/`tentative` → upsert ; `cancelled` → delete).
4. **Reflection.** Quand notre push (Plan 3) crée un event GCal, Google nous re-notifie. On l'ignore (lookup `google_event_id` dans `wp_tb_bookings`).
5. **Renewal.** Cron quotidien `tb/watch_renew_check` : si le channel expire dans < 24h, on le renouvelle automatiquement.
6. **Fallback.** Cron `tb/google_pull_all` toutes les 15 min : exécute un pull même si le webhook n'a rien reçu (firewall, DNS, etc.). No-op si rien à pull.

### Pré-requis Google Cloud

- Webhook URL **doit** être HTTPS et publique. Google rejette `http://` et les IPs RFC1918.
- En dev local : utiliser un tunnel **ngrok** (`ngrok http 8080`) ou **Cloudflare Tunnel** et configurer `WP_HOME` / `WP_SITEURL` sur l'URL publique le temps des tests.

### Diagnostics

- **WP admin → Trinity Booking → Google → Synchronisation entrante** : statut watch (channel id, expires_at), dernier full sync, présence du sync token, boutons "Démarrer / Arrêter watch" et "Forcer un pull maintenant".
- **WP-CLI** : `wp trinity-booking doctor` — vérifie OAuth, statut watch, et lance un pull de test (rapporte upserted / deleted / reflection-ignored).
- **Journal** : Trinity Booking → Journal, filtre `direction=g_to_wp` ou `entity=watch`.

### Désactivation propre

Avant de désactiver le plugin, **cliquer Arrêter le watch** pour libérer le channel côté Google. Sinon le channel expire de lui-même en ≤ 7 jours. La désactivation WP n'appelle PAS `stopChannel()` automatiquement (le bootstrap du plugin n'est pas garanti dans le contexte de désactivation).

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

## Troubleshooting

### Le webhook Google n'arrive pas → `BusyBlock` n'apparaît pas

1. Vérifier dans **Trinity Booking → Google → Synchronisation entrante** que le watch est actif (id de channel + `expires_at` futur).
2. Vérifier que votre URL est HTTPS (Google rejette HTTP) :

   ```bash
   wp option get home
   ```

3. Tester la réception webhook depuis l'extérieur :

   ```bash
   curl -X POST https://votresite.fr/wp-json/trinity-booking/v1/google/webhook \
     -H "X-Goog-Resource-State: sync" \
     -H "X-Goog-Channel-Id: test" \
     -H "X-Goog-Channel-Token: <watch_token_secret>"
   ```

   Attendu : `200`. Si `401` → le token ne correspond pas (re-créer le watch). Si timeout → firewall / WAF bloque, vérifier les logs du reverse proxy.

4. Cron fallback : toutes les 15 min, `tb/google_pull_all` exécute un pull manuel. Vérifier avec :

   ```bash
   wp cron event list | grep tb_google_pull_all
   ```

### `wp trinity-booking doctor` signale `oauth_failed`

Le refresh token est invalide (révoqué côté Google, ou clé `TRINITY_BOOKING_ENC_KEY` modifiée). Reconnecter depuis **Trinity Booking → Google → Configuration OAuth → Reconnecter mon Google Calendar**.

### `syncToken expired` (410 Gone) dans le sync log

Normal après une longue période sans pull (Google invalide les tokens > 7 jours). `SyncEngine` reset automatiquement le token et refait un full sync au prochain pull. Pas d'action requise.

### L'e-mail admin "à valider" n'arrive pas

1. Vérifier la config SMTP : `wp option get admin_email` + plugin SMTP installé (ex: WP Mail SMTP).
2. Tester via **Trinity Booking → Templates → booking.pending.admin → Envoyer un test**.
3. Si le test n'arrive pas : log dans **Trinity Booking → Journal** filtre `entity=booking action=mail_sent` — vérifier `error_message`.

### Le SPA admin est vide / pas de tabs

`assets/dist/index.jsx.js` non trouvé. Vérifier que le ZIP de release contient bien `assets/dist/`. Si install via `git clone` : `npm install && npm run build`.

## Désinstallation

**Outils → Désinstaller des extensions** ne supprime pas les données par défaut. Pour un nettoyage complet :

```bash
wp db query "DROP TABLE wp_tb_services, wp_tb_bookings, wp_tb_busy_blocks, wp_tb_google_accounts, wp_tb_sync_log, wp_tb_mail_templates;"
wp option delete tb_db_version tb_decision_secret tb_google_client_id tb_google_client_secret tb_legal_page_id tb_booking_retention_days TRINITY_BOOKING_ENC_KEY_FALLBACK
```

## Licence

GPL v2 or later
