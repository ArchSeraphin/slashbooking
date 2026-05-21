# slashbooking — Design

**Date :** 2026-05-19
**Statut :** validé pour passage au plan d'implémentation
**Slug plugin :** `slashbooking`

## 1. Objectif

Plugin WordPress de prise de rendez-vous commerciaux en ligne, spécialisé pour deux services à durée fixe :

- **Photovoltaïque** — 1h30 (devis solaire à domicile)
- **Borne de recharge IRVE** — 45 min (devis installation à domicile)

Le différenciateur fonctionnel est la **synchronisation bidirectionnelle avec Google Calendar** : un événement créé dans Google Calendar bloque automatiquement le créneau côté formulaire, et chaque RDV pris depuis le site crée un événement dans Google Calendar.

La V1 est explicitement mono-commercial (un seul Google Calendar) avec une architecture qui n'exclut pas un passage multi-commercial en V2.

## 2. Périmètre

### Dans la V1
- 2 services pré-configurés (PV 90 min, IRVE 45 min), durée et règles éditables ; tout nouveau service ajouté via l'admin fonctionne sans code.
- Page de réservation publique exposée via **bloc Gutenberg** et **shortcode** `[slashbooking service="pv"]`.
- Parcours client : choix du service → choix de la date → choix d'un créneau libre → saisie des informations → page de confirmation.
- Règles de réservation : durée fixe par service, pas de la grille de 15 min, buffer 30 min avant/après (réglable), délai minimum 24 h, horizon 60 jours.
- Horaires d'ouverture configurables par service : Lun–Ven 9h–18h, Sam 9h–13h par défaut.
- **Validation manuelle admin** avec deux chemins équivalents :
  - Boutons "Confirmer" / "Refuser" dans l'e-mail admin (URL signée HMAC, expiration 72 h, idempotente, sans session WP).
  - Dashboard admin React pour gestion en lot et historique.
- Statuts : `pending` (créé, en attente admin), `confirmed`, `rejected`, `cancelled` (par le client), `completed` (passé).
- **Annulation client** via lien sécurisé HMAC dans l'e-mail de confirmation.
- **Sync bidirectionnelle Google Calendar** via OAuth 2.0 utilisateur (le commercial autorise l'accès à son calendrier personnel ; pas de service account, qui exigerait Google Workspace + délégation domaine) :
  - WP → Google : création/mise à jour/suppression d'événement selon statut.
  - Google → WP : événements créés directement dans GCal (déjeuner, congé, autre RDV…) deviennent des `BusyBlock` qui bloquent les créneaux côté formulaire.
  - Push notifications Google (watch channel) avec renouvellement automatique tous les 6 jours, fallback cron toutes les 15 min.
- Code couleur GCal selon statut : `[À VALIDER]` orange (`colorId=6`) pour `pending`, vert (`colorId=10`) pour `confirmed`, événement supprimé pour `rejected`/`cancelled`. Valeurs `colorId` exposées en options pour personnalisation.
- **Notifications e-mail** : 6 événements, templates HTML personnalisables avec système de tags `{{...}}`, éditeur CodeMirror avec aperçu live, version texte auto-générée, e-mail de test.
- Pièce jointe `.ics` (RFC 5545) sur l'e-mail de confirmation.
- Reminder J-1 (cron quotidien à 10h00 du fuseau horaire WordPress configuré).
- **Dashboard admin** (React + `@wordpress/components`) : liste filtrée des RDV, vue agenda, fiche détail, gestion des services, gestion du compte Google, journal de synchronisation, éditeur de templates.
- **WP-CLI** : `wp slashbooking sync`, `wp slashbooking doctor` (diagnostics OAuth + webhook).
- **i18n** FR par défaut, EN bonus.
- **RGPD** : consentement explicite au formulaire, hooks exporters/erasers WP, rétention logs 30 jours.

### Hors V1 (V2+)
- Multi-commercial (dispatch round-robin, choix client), paiement / acompte (Stripe, WooCommerce), SMS / WhatsApp, visio auto (Meet, Zoom), intégration CRM externe (HubSpot, Pipedrive), Outlook / iCloud / CalDAV, questionnaire de qualification long, tarifs dynamiques.

## 3. Stack & dépendances

| Couche | Choix |
| --- | --- |
| Backend | PHP 8.1+, WordPress 6.5+ |
| Autoload | Composer, PSR-4 |
| Google API | `google/apiclient` (Calendar API v3), chargé via Mozart/PHP-Scoper pour isolation namespace |
| Background jobs | Action Scheduler |
| Front réservation | JS vanilla (~15 ko), FullCalendar v6 pour la grille |
| Bloc Gutenberg | `@wordpress/scripts`, build via webpack natif WP |
| Admin SPA | React + `@wordpress/components` + `@wordpress/data` |
| Tests | PHPUnit 10 + Brain Monkey ; Playwright pour E2E |
| Qualité | PHPStan niveau 8, PHPCS WordPress-Extra, Prettier, ESLint |

## 4. Architecture modulaire

Découpage strict avec une responsabilité par module. Chaque module a une interface publique étroite et peut être testé isolément.

```
slashbooking/
├── slashbooking.php           Bootstrap, activation/désactivation, version
├── composer.json
├── src/
│   ├── Plugin.php                Container DI léger, registre des hooks
│   ├── Domain/                   Entités pures, sans dépendance WP
│   │   ├── Service.php
│   │   ├── Booking.php           Aggregate root
│   │   ├── TimeSlot.php
│   │   ├── BusyBlock.php
│   │   └── BookingStatus.php     Enum
│   ├── Availability/
│   │   ├── AvailabilityCalculator.php   Combine horaires + bookings + busy + buffer
│   │   └── SlotGenerator.php
│   ├── Booking/
│   │   ├── CreateBooking.php
│   │   ├── ConfirmBooking.php
│   │   ├── RejectBooking.php
│   │   ├── CancelBooking.php
│   │   └── DecisionTokenSigner.php      HMAC SHA-256
│   ├── Google/
│   │   ├── GoogleClientFactory.php      OAuth + refresh
│   │   ├── CalendarGateway.php          Interface + impl
│   │   ├── WebhookController.php        REST /webhook
│   │   ├── WatchChannelManager.php      Création/renouvellement
│   │   └── SyncEngine.php               Pull incrémental par syncToken
│   ├── Notifications/
│   │   ├── MailDispatcher.php
│   │   ├── TemplateRenderer.php         Parse {{tags}}
│   │   ├── IcsBuilder.php
│   │   └── templates/                   Templates HTML par défaut
│   ├── Persistence/
│   │   ├── BookingRepository.php
│   │   ├── BusyBlockRepository.php
│   │   ├── ServiceRepository.php
│   │   ├── GoogleAccountRepository.php
│   │   ├── MailTemplateRepository.php
│   │   └── SyncLogRepository.php
│   ├── Http/
│   │   ├── PublicBookingController.php
│   │   ├── AdminBookingController.php
│   │   └── DecisionController.php
│   ├── Admin/
│   │   ├── AdminMenu.php
│   │   ├── Assets.php
│   │   └── react-app/
│   ├── PublicFront/
│   │   ├── BookingBlock.php
│   │   ├── Shortcode.php
│   │   └── assets/
│   └── Cli/
│       ├── SyncCommand.php
│       └── DiagnoseCommand.php
├── assets/dist/
├── languages/
└── tests/
    ├── Unit/
    ├── Integration/
    └── E2E/
```

**Règles d'isolation :**
- `Domain/` ne dépend de rien (ni WP, ni Google). 100 % testable en unit pur.
- `Persistence/` est le seul module à toucher `$wpdb`.
- `Google/` est le seul module à instancier le client Google.
- `Http/` ne contient pas de logique métier : il sanitize, appelle un cas d'usage, retourne un `WP_REST_Response`.

## 5. Modèle de données

Toutes les tables sont préfixées `wp_sb_`. Migration via une classe `Migrator` versionnée à l'activation.

### `wp_sb_services`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `slug` | VARCHAR(64) UNIQUE | `pv`, `irve` |
| `name` | VARCHAR(160) | "Photovoltaïque" |
| `duration_min` | SMALLINT | 90, 45 |
| `buffer_before_min` | SMALLINT | défaut 0 |
| `buffer_after_min` | SMALLINT | défaut 30 |
| `min_lead_time_hours` | SMALLINT | défaut 24 |
| `max_horizon_days` | SMALLINT | défaut 60 |
| `color` | VARCHAR(7) | hex pour UI |
| `active` | TINYINT(1) | |
| `sort_order` | SMALLINT | |
| `settings` | LONGTEXT | JSON : horaires hebdo, exceptions, capacité |
| `created_at`, `updated_at` | DATETIME | |

### `wp_sb_bookings`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `public_uid` | CHAR(36) UNIQUE | UUID v4, utilisé dans URL d'annulation |
| `service_id` | BIGINT FK | |
| `status` | VARCHAR(16) | pending, confirmed, rejected, cancelled, completed |
| `starts_at_utc`, `ends_at_utc` | DATETIME | UTC pour comparaisons |
| `timezone` | VARCHAR(64) | IANA, ex `Europe/Paris` |
| `customer_name` | VARCHAR(160) | |
| `customer_email` | VARCHAR(200) | |
| `customer_phone` | VARCHAR(40) | |
| `customer_address` | TEXT | |
| `customer_meta` | LONGTEXT | JSON : type logement, etc. |
| `notes` | TEXT | |
| `google_event_id`, `google_event_etag` | VARCHAR(255) NULL | |
| `decision_token_hash` | VARCHAR(64) NULL | SHA-256 du token courant |
| `reminder_sent_at` | DATETIME NULL | anti-doublon rappel J-1 |
| `created_at`, `updated_at` | DATETIME | |
| `ip`, `user_agent` | VARCHAR | audit |

Index : `(status, starts_at_utc)`, `(google_event_id)`, `(public_uid)`.

### `wp_sb_busy_blocks`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `source` | VARCHAR(16) | `google`, `manual` |
| `source_id` | VARCHAR(255) | event id GCal (UNIQUE par compte) |
| `google_account_id` | BIGINT FK | |
| `starts_at_utc`, `ends_at_utc` | DATETIME | |
| `summary` | VARCHAR(255) | |
| `last_synced_at` | DATETIME | |

Index : `(google_account_id, source_id)`, `(starts_at_utc, ends_at_utc)`.

### `wp_sb_google_accounts`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `label` | VARCHAR(120) | "Calendrier commercial" |
| `calendar_id` | VARCHAR(200) | `primary` ou e-mail |
| `oauth_refresh_token_enc` | LONGTEXT | chiffré `sodium_crypto_secretbox` |
| `oauth_access_token_enc` | LONGTEXT | TTL court |
| `oauth_expires_at` | DATETIME | |
| `watch_channel_id` | VARCHAR(80) | UUID v4 |
| `watch_resource_id` | VARCHAR(255) | retour Google |
| `watch_token_secret` | VARCHAR(80) | vérifie X-Goog-Channel-Token |
| `watch_expires_at` | DATETIME | |
| `sync_token` | VARCHAR(255) | nextSyncToken Google |
| `last_full_sync_at` | DATETIME | |

V1 : une seule ligne attendue. Le schéma est néanmoins multi-compte pour préparer la V2 sans migration destructive.

### `wp_sb_sync_log`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `ts` | DATETIME | |
| `level` | VARCHAR(10) | info, warn, error |
| `direction` | VARCHAR(8) | `wp_to_g`, `g_to_wp`, `internal` |
| `entity` | VARCHAR(32) | booking, busy_block, watch |
| `entity_id` | BIGINT NULL | |
| `google_event_id` | VARCHAR(255) NULL | |
| `action` | VARCHAR(40) | create, update, delete, refresh_token, watch_renew… |
| `payload` | LONGTEXT | JSON tronqué |
| `status` | VARCHAR(16) | ok, retry, failed |
| `error_message` | TEXT NULL | |

Rétention : purge des entrées > 30 jours via cron quotidien.

### `wp_sb_mail_templates`

| Colonne | Type | Notes |
| --- | --- | --- |
| `id` | BIGINT PK | |
| `event_key` | VARCHAR(64) UNIQUE | ex `booking.confirmed.client` |
| `subject` | VARCHAR(255) | accepte tags |
| `html_body` | LONGTEXT | template HTML |
| `text_body` | LONGTEXT NULL | optionnel, sinon auto-généré |
| `enabled` | TINYINT(1) | |
| `updated_at`, `updated_by` | | |

À l'activation : seed avec templates par défaut. Si l'utilisateur supprime sa version personnalisée → fallback sur le template par défaut bundlé.

## 6. Flux fonctionnels

### 6.1 Création d'une réservation (parcours client)

1. Client charge la page contenant le bloc Gutenberg / shortcode → l'éditeur a déjà précisé le service (ex `service="pv"`).
2. Front appelle `GET /wp-json/slashbooking/v1/availability?service=pv&from=2026-05-20&to=2026-06-20` → renvoie créneaux libres déjà calculés (horaires – bookings actifs – busy blocks – buffers).
3. Client choisit un créneau, remplit le formulaire (nom, e-mail, téléphone, adresse, notes, consentement RGPD).
4. Front appelle `POST /wp-json/slashbooking/v1/bookings` avec nonce + honeypot.
5. Backend :
   - `CreateBooking` valide entrées + revérifie dispo dans la même transaction (anti-race).
   - Insère booking `status=pending`, génère `public_uid` + `decision_token`.
   - Push job Action Scheduler : `sb/create_gcal_event` (booking_id).
   - Push job : `sb/send_mail` x 2 (client "demande reçue", admin "à valider + boutons").
6. Réponse : `{ booking_id, public_uid, redirect_url }` → front affiche page "demande envoyée".
7. Job `create_gcal_event` exécute : crée l'événement GCal `[À VALIDER] <Service> · <Nom>`, couleur orange (colorId `6`), description avec coordonnées client + lien admin. Stocke `google_event_id` + `etag` sur le booking. Idempotent : si déjà créé, no-op.

### 6.2 Validation admin (chemin e-mail)

1. Admin reçoit e-mail avec boutons "Confirmer" et "Refuser" pointant vers `GET /wp-json/slashbooking/v1/decide?booking={id}&action=confirm|reject&exp={unix_ts}&sig={hmac}`.
2. `DecisionController` :
   - Vérifie signature HMAC (`hash_hmac('sha256', "$booking|$action|$exp", $secret)`).
   - Vérifie expiration (`exp > now`).
   - Vérifie idempotence (`decision_token_hash` correspond + statut courant).
   - Exécute `ConfirmBooking` ou `RejectBooking`.
3. Affiche page d'atterrissage simple : "✓ RDV confirmé" / "RDV refusé".
4. `ConfirmBooking` : statut → `confirmed`, push jobs `update_gcal_event` (couleur verte, préfixe retiré), `send_mail` client (e-mail confirmation + .ics joint).
5. `RejectBooking` : statut → `rejected`, push jobs `delete_gcal_event`, `send_mail` client ("désolé" + lien vers nouvelle réservation).

### 6.3 Validation admin (chemin dashboard)

Dashboard React appelle `POST /wp-json/slashbooking/v1/admin/bookings/{id}/confirm|reject|cancel` (capability `slashbooking_manage`, nonce). Mêmes cas d'usage que ci-dessus.

### 6.4 Annulation client

E-mail de confirmation contient un lien `GET /wp-json/slashbooking/v1/cancel?uid={public_uid}&exp={ts}&sig={hmac}` → page de confirmation d'annulation → `CancelBooking` → statut `cancelled`, event GCal supprimé, e-mail "annulation prise en compte" au client + notification admin.

### 6.5 Sync entrante Google → WP

1. Admin (ou commercial) crée un événement directement dans Google Calendar.
2. Google envoie un POST au webhook : `POST /wp-json/slashbooking/v1/google/webhook` avec en-têtes `X-Goog-Resource-State`, `X-Goog-Channel-Id`, `X-Goog-Channel-Token`.
3. `WebhookController` :
   - Vérifie le `X-Goog-Channel-Token` contre le `watch_token_secret` stocké.
   - Répond `200` immédiatement.
   - Push job `sb/google_pull` (account_id).
4. Job `google_pull` :
   - Appelle `events.list` avec `syncToken` courant → récupère les diffs.
   - Pour chaque event modifié :
     - Si `google_event_id` correspond à un booking existant → ignore (notre propre push réfléchi, sinon merge attributs autorisés).
     - Sinon → upsert `BusyBlock`.
   - Si event supprimé → supprime `BusyBlock`.
   - Met à jour `sync_token`.
5. Fallback : cron toutes les 15 min exécute `google_pull` pour chaque compte (no-op si rien à pull).

### 6.6 Watch channel renewal

- Job `sb/watch_renew` programmé tous les 6 jours (channels Google expirent à 7 jours).
- Crée un nouveau channel, stocke `watch_channel_id` + `watch_resource_id` + `watch_token_secret`, stoppe l'ancien.

### 6.7 Rappel J-1

Cron quotidien à 10h00 du fuseau WordPress : sélectionne bookings `status=confirmed` dont `starts_at_utc` tombe dans 24h ± 1h, push job `send_mail` (`booking.reminder.client`). Marquage anti-doublon sur booking (`reminder_sent_at`).

## 7. API REST

Namespace `slashbooking/v1`. Tous les endpoints utilisent le schéma REST WP standard.

| Méthode | Endpoint | Auth | Description |
| --- | --- | --- | --- |
| `GET` | `/services` | public | Liste des services actifs (cache 5 min) |
| `GET` | `/availability` | public + nonce | Créneaux libres pour un service sur une plage |
| `POST` | `/bookings` | public + nonce + honeypot + rate-limit | Crée un booking (`pending`) |
| `GET` | `/decide` | HMAC token | Bouton e-mail admin (confirm/reject) |
| `GET` | `/cancel` | HMAC token | Lien annulation client |
| `POST` | `/google/webhook` | header Google | Push notifications Google |
| `GET` | `/admin/bookings` | capability | Liste paginée filtrable |
| `POST` | `/admin/bookings/{id}/{action}` | capability + nonce | confirm / reject / cancel / restore |
| `GET` | `/admin/sync-log` | capability | Lecture du journal |
| `POST` | `/admin/google/oauth/start` | capability | Initie OAuth |
| `GET` | `/admin/google/oauth/callback` | capability | Callback OAuth |
| `POST` | `/admin/mail-templates/{event_key}` | capability + nonce | Sauvegarde template |
| `POST` | `/admin/mail-templates/{event_key}/test` | capability + nonce | Envoie un test |

Rate-limiting (anti-bot) : `POST /bookings` ≤ 5 / minute / IP via transient WP.

## 8. Templates e-mail

### 8.1 Événements

| `event_key` | Destinataire | Déclencheur |
| --- | --- | --- |
| `booking.pending.client` | client | création |
| `booking.pending.admin` | admin (`admin_email` ou option custom) | création |
| `booking.confirmed.client` | client | confirmation (joint `.ics`) |
| `booking.rejected.client` | client | refus |
| `booking.cancelled.client` | client | annulation par client |
| `booking.reminder.client` | client | J-1 |

### 8.2 Tags disponibles

- Client : `{{customer_name}}`, `{{customer_email}}`, `{{customer_phone}}`, `{{customer_address}}`.
- RDV : `{{service_name}}`, `{{service_duration}}`, `{{appointment_date}}` (format long locale), `{{appointment_time}}` (HH:mm locale), `{{appointment_end}}`, `{{timezone}}`, `{{notes}}`.
- Actions : `{{cancel_url}}`, `{{confirm_url}}`, `{{reject_url}}`, `{{ics_url}}`.
- Site : `{{site_name}}`, `{{site_url}}`, `{{admin_email}}`.
- Branding : `{{company_logo}}`, `{{company_phone}}` (réglages plugin).

**Rendu :** `TemplateRenderer` parse `{{...}}` en remplaçant par valeur échappée HTML par défaut. Un tag inconnu est laissé tel quel et loggué en warn.

### 8.3 Éditeur admin

- Éditeur HTML avec CodeMirror 6 (coloration HTML).
- Panneau droit "Aperçu live" rendant avec dataset factice.
- Bouton "Insérer un tag" (dropdown listant tags par catégorie avec description).
- Bouton "Restaurer le template par défaut" (suppression de la ligne custom).
- Bouton "Envoyer un test" (utilise l'e-mail courant de l'utilisateur WP).
- Champ "Version texte" : si vide, généré automatiquement depuis HTML via `wp_strip_all_tags`. Sinon utilisé tel quel.

## 9. Sécurité

- **Capabilities WP custom** : `slashbooking_manage` (admins par défaut), `slashbooking_view`.
- **Nonces WP** sur toutes les requêtes admin.
- **HMAC** : secret 32 octets aléatoires généré à l'activation, stocké dans option `sb_decision_secret`. Rotation possible via WP-CLI (`wp slashbooking rotate-decision-secret`).
- **Chiffrement OAuth** : refresh tokens chiffrés via `sodium_crypto_secretbox` avec clé dans `wp-config.php` (`define('SLASHBOOKING_ENC_KEY', '...')`). Fallback option WP si non définie, avec warning admin.
- **Webhook Google** : header `X-Goog-Channel-Token` vérifié contre le secret stocké à la création du channel. Réponse `200` immédiate sans révéler d'info.
- **Anti-bot** : honeypot caché + délai minimum côté front (≥ 3 s entre chargement et submit). Pas de reCAPTCHA en V1.
- **Rate-limit** : transient `sb_rate_<ip>` incrémenté par requête, fenêtre 60 s.
- **Sanitization/escaping** : `sanitize_text_field`, `sanitize_email`, `wp_kses_post` pour notes admin. Sortie via `esc_html`, `esc_attr`, `esc_url`.

## 10. RGPD

- **Consentement explicite** : case à cocher obligatoire au formulaire, texte légal réglable dans les options.
- **Mentions légales** : champ de configuration plugin référençant la page de politique de confidentialité.
- **Export personnel** : hook `wp_privacy_personal_data_exporters` → exporte bookings + e-mails liés.
- **Effacement** : hook `wp_privacy_personal_data_erasers` → anonymise (remplace nom/email/téléphone/adresse par hash) en conservant la trace agrégée.
- **Logs** : pas de PII dans `sync_log` sauf `customer_email` masqué (`a***@d***`). Purge auto 30 jours.
- **Conservation bookings** : politique configurable (par défaut : 3 ans après date du RDV, purge cron mensuelle).

## 11. Tests

| Niveau | Cibles | Outils |
| --- | --- | --- |
| Unit | `Domain/`, `Availability/`, `Booking/*`, `TemplateRenderer`, `DecisionTokenSigner`, `IcsBuilder` | PHPUnit + Brain Monkey |
| Integration | `Persistence/` (real `$wpdb` SQLite), `Http/` (WP REST test case), `Google/SyncEngine` avec mock Google client | PHPUnit + wp-phpunit |
| E2E | Parcours client complet sur site WP local, dashboard admin, double webhook GCal | Playwright |
| Charge | 50 réservations concurrentes sur même créneau → vérifier qu'une seule passe | script ad-hoc Bash + curl |

Couverture cible : 80 %+ sur `Domain/`, `Availability/`, `Booking/`.

## 12. Observabilité

- Journal `wp_sb_sync_log` consultable depuis le dashboard (filtre par direction, statut, période).
- Page "Diagnostics" : état OAuth (token valide, expiration), état watch channel (id, expires_at), dernier `sync_token`, dernier pull, taille des queues Action Scheduler.
- `wp slashbooking doctor` : exécute en CLI les mêmes checks + tente une création + suppression d'event de test.

## 13. Internationalisation

- Text domain : `slashbooking`.
- Fichiers : `languages/slashbooking.pot`, `languages/slashbooking-fr_FR.{po,mo}`, EN bonus.
- Tous les libellés (front + admin + e-mails par défaut) passent par `__()`, `_x()`, `_n()`.
- Formats date/heure via `wp_date()` (respecte fuseau WP + locale).

## 14. Installation & déploiement

- Plugin distribué en ZIP (pas WP.org en V1).
- Activation : crée tables, seed services (PV + IRVE), seed templates par défaut, génère `sb_decision_secret`, programme jobs cron.
- Désactivation : conserve données, désinscrit cron, supprime watch channel.
- Désinstallation (`uninstall.php`) : suppression des tables + options + transients seulement si option "Effacer les données à la désinstallation" cochée.
- Composer dump-autoload optimisé en production.

## 15. Risques identifiés

| Risque | Mitigation |
| --- | --- |
| Conflit de namespaces entre `google/apiclient` et un autre plugin | PHP-Scoper / Mozart → namespace `Slash\Booking\Vendor\Google\…` |
| Webhook Google non-reçu (firewall, DNS, HTTPS) | Cron fallback 15 min + diagnostics CLI |
| Double-clic admin sur "Confirmer" | Idempotence stricte sur `ConfirmBooking` |
| Race condition sur dernier créneau | Re-vérification dispo dans transaction `CreateBooking` |
| Token HMAC fuité (forward d'e-mail) | Expiration 72 h + invalidation au changement de statut |
| Quotas Google API atteints | Action Scheduler avec backoff exponentiel + alerte admin |
| Décalage fuseau WP vs commercial | Tout en UTC en base, rendu via `wp_date()` |

## 16. Critères d'acceptation V1

- Un visiteur peut prendre un RDV en moins de 90 secondes sur mobile sans erreur visible.
- Un événement créé manuellement dans GCal apparaît comme indispo côté formulaire en moins de 30 secondes (webhook OK) ou 15 minutes (fallback cron).
- L'admin peut confirmer ou refuser un RDV en un clic depuis son e-mail sans ouvrir WP.
- L'admin peut éditer un template HTML, voir l'aperçu et envoyer un test sans toucher au code.
- Un cycle complet (création → validation → annulation client) se fait sans intervention manuelle dans Google Calendar.
- Le plugin fonctionne sur une installation WordPress 6.5+ standard avec PHP 8.1+, sans dépendance externe à installer manuellement (composer install fait au build).
- Toutes les chaînes front + e-mails par défaut sont en français.

## 17. Étapes suivantes

1. Plan d'implémentation (skill `writing-plans`) — découpage en tâches indépendantes, ordre, points de revue.
2. Bootstrap repo : `composer init`, structure squelette, CI minimale.
3. Itération 1 : Domain + Availability + Persistence + parcours public minimal sans Google.
4. Itération 2 : Google OAuth + push outbound (WP → GCal).
5. Itération 3 : Webhook + pull inbound (GCal → WP).
6. Itération 4 : Templates e-mail + dashboard admin.
7. Itération 5 : Polish, i18n, RGPD, tests E2E, packaging.
