# Changelog

Tous les changements notables de **trinity-booking** sont consignés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) et le projet utilise [Semantic Versioning](https://semver.org/).

---

## [1.0.1] — 2026-05-20

### Fixed

- **Activation fatal sur fresh install.** `Plugin::register()` instanciait `DecisionTokenSigner` avec un secret vide avant que `register_activation_hook` n'ait pu seeder l'option `tb_decision_secret` → `InvalidArgumentException: Decision secret must be at least 16 characters`. Fix : `Activator::ensureDecisionSecret()` est maintenant publique et appelée en tête de `Plugin::register()` (idempotent). Bug latent depuis Plan 2.

---

## [1.0.0] — 2026-05-20

Première release stable. Périmètre V1 fermé selon `docs/superpowers/specs/2026-05-19-trinity-booking-design.md`.

### Added — Plan 5 : Polish V1

- Éditeur de templates e-mail dans le dashboard admin (CodeMirror 6 + preview live + insertion de tag + envoi d'un test + restauration du défaut) pour les 6 événements (`booking.pending.client/admin`, `booking.confirmed.client`, `booking.rejected.client`, `booking.cancelled.client`, `booking.reminder.client`).
- Internationalisation complète : `languages/trinity-booking.pot` généré, traduction `fr_FR` fournie.
- Conformité RGPD :
  - Privacy Exporter (`wp_privacy_personal_data_exporters`) — exporte tous les bookings matchant un e-mail.
  - Privacy Eraser (`wp_privacy_personal_data_erasers`) — anonymise via SHA-256 + `@anon.invalid`, conserve les agrégats.
  - Masquage e-mails dans `sync_log` (`a***@d***`).
  - Option `tb_legal_page_id` pour lien "Mentions légales" sous case de consentement.
  - Cron mensuel `tb/purge_old_bookings` (rétention par défaut 3 ans après `ends_at_utc`).
- Isolation des dépendances vendor via PHP-Scoper (`Trinity\Booking\Vendor\Google\…`, etc.) — élimine le risque de collision avec d'autres plugins WordPress.
- Script `bin/build-release.sh` produit un ZIP de release reproductible (composer no-dev + npm build + scoping + autoload classmap + checksum SHA-256).
- Documentation `README.md` complète (walkthrough Google Cloud Console, troubleshooting).

### Added — Plan 4 : Webhook + pull Google → WP

- Push notifications Google : `WatchChannelManager` (start/stop/renew) + endpoint REST `POST /google/webhook` (vérif HMAC `X-Goog-Channel-Token`).
- Pull incrémental via `events.list` + `syncToken` : `SyncEngine` + handler Action Scheduler `tb/google_pull`.
- Reflection : ignore les events GCal créés par notre propre push (Plan 3) via `BookingRepository::findByGoogleEventId`.
- Cron `tb/watch_renew_check` (quotidien) + `tb/google_pull_all` (15 min fallback).
- Diagnostics étendus : SPA `GooglePage` montre statut watch, dernier sync, sync token ; `wp trinity-booking doctor` étendu.
- Upgrade PHPStan 1.x → 2.x avec `treatPhpDocTypesAsCertain: false`.

### Added — Plan 3 : Google OAuth + push WP → GCal

- OAuth 2.0 utilisateur (refresh token chiffré via `sodium_crypto_secretbox`).
- Push WP → GCal via Action Scheduler `tb/push_gcal_event` (create / update / delete selon statut).
- Code couleur GCal : orange `colorId=6` (pending), vert `colorId=10` (confirmed), delete (rejected/cancelled).
- Journal de synchronisation `wp_tb_sync_log` (cron quotidien `tb_purge_sync_log` 30j).
- CLI `wp trinity-booking doctor` (état OAuth + probe create/delete event).
- SPA admin : `GooglePage` (Configuration OAuth + Google Calendar) + `SyncLogPage`.

### Added — Plan 2 : Notifications e-mail + validation admin

- 6 templates HTML personnalisables (`wp_tb_mail_templates`) + tags `{{...}}` + fallback texte auto via `wp_strip_all_tags`.
- Pièce jointe `.ics` RFC 5545 sur l'e-mail de confirmation.
- Reminder J-1 (cron quotidien `tb_send_daily_reminders` à 10h00 site TZ).
- Validation admin : boutons HMAC dans l'e-mail (72h, idempotent) + dashboard React minimal.
- Annulation client via lien HMAC dans e-mail de confirmation.
- Capabilities WP : `trinity_booking_manage`, `trinity_booking_view`.

### Added — Plan 1 : Fondations

- Architecture modulaire (Domain / Persistence / Availability / Booking / Http / PublicFront / Cli).
- Modèle de données : 6 tables `wp_tb_*` (`services`, `bookings`, `busy_blocks`, `google_accounts`, `sync_log`, `mail_templates`).
- 2 services seed à l'activation (`pv` 90min, `irve` 45min).
- REST API publique : `GET /services`, `GET /availability`, `POST /bookings`.
- Bloc Gutenberg + shortcode `[trinity_booking service="pv|irve"]`.
- Anti-bot : honeypot + délai min + rate-limit transient.
- Buffer 30 min + délai 24h + horizon 60 jours.

---

[1.0.0]: https://github.com/trinity/booking/releases/tag/v1.0.0
