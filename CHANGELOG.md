# Changelog

Tous les changements notables de **slashbooking** sont consignés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) et le projet utilise [Semantic Versioning](https://semver.org/).

---

## [1.0.20] — 2026-05-22

### Added

- **Rôle WP "Éditeur" autorisé à utiliser le plugin.** `Capabilities::install()` accorde maintenant `slashbooking_view` + `slashbooking_manage` aux rôles `administrator` ET `editor`. Couvre le cas d'usage typique TPE : l'office manager / commercial qui gère les RDV au quotidien sans avoir besoin d'accès admin technique (Réglages WP, autres plugins, etc).
- **Migration automatique des caps sur upgrade.** Nouvelle méthode `Capabilities::syncOnUpgrade()` appelée depuis `Plugin::register()` qui compare l'option `slashbooking_caps_revision` (entier) à `Capabilities::REVISION` (constante). Si la revision stockée est inférieure, ré-appelle `install()` puis bump l'option. Évite de demander à l'utilisateur de désactiver/réactiver le plugin pour bénéficier d'un nouveau cap layout. Idempotent et cheap (single `get_option` à chaque page load).

---

## [1.0.19] — 2026-05-22

### Fixed

- **PUC ne déclenchait pas la notif de mise à jour** sur certains environnements (observé sur un site qui rapporte `get_bloginfo('version') === '7.0'`). Cause racine identifiée via debug script terrain : `Slash\Booking\Vendor\YahnisElsts\PluginUpdateChecker\v5p6\InstalledPackage::getFileHeader()` n'appelle `_cleanup_header_comment()` que si `function_exists()` retourne `true` — quand ce n'est pas le cas, la valeur du header `Version:` est stockée brute, avec les **11 espaces de padding** de mon alignement visuel. Stocké en DB sous `external_updates-slashbooking.update.version = "           1.0.18"`. `version_compare()` côté injection se trompe à cause du whitespace → le transient `update_plugins` n'est jamais alimenté → WP affiche "à jour".
- **Fix défensif** : 2 filtres `puc_request_{info,update}_result-slashbooking` qui font `trim($result->version)` après la requête PUC, indépendamment de la dispo de `_cleanup_header_comment`. Le padding du `Version:` dans `slashbooking.php` est aussi compacté à 1 espace pour réduire la surface d'erreur.

### Changed

- **Suppression du guard `is_admin()`** autour de `UpdateChecker::bootstrap()` dans `Plugin::register()`. PUC doit installer ses filtres dans tous les contextes qui peuvent rafraîchir le transient `update_plugins` — y compris WP-Cron (`DOING_CRON=true`, `is_admin()=false`). Le guard empêchait les checks programmés de tourner.
- **`readme.txt` Changelog** repassé au format wp.org standard `= X.Y.Z =` (la date est désormais dans le contenu de chaque entrée).

---

## [1.0.18] — 2026-05-22

### Added

- **`readme.txt` au format wp.org** à la racine du repo + dans le ZIP. PUC le lit lors d'un clic sur **"Afficher les détails"** dans la liste des extensions et affiche une fiche complète (Description, Installation, FAQ, Changelog, Upgrade Notice) comme pour un plugin du dépôt officiel. Format `=== H1 ===`, `== H2 ==`, listes `*`, headers `Contributors / Tags / Requires at least / Tested up to / Stable tag / Requires PHP`.

---

## [1.0.17] — 2026-05-22

### Fixed

- **Fatal PUC encore présent en v1.0.16** malgré le fix des clés de registre. Vraie cause racine : le bootstrap faisait `if (!class_exists(PucFactory::class)) require plugin-update-checker.php`, mais comme le build composer utilise `--classmap-authoritative`, `PucFactory.php` est autoloadable directement via le classmap → `class_exists` retourne `true` → le `require` est skip → `load-v5p6.php` n'est jamais exécuté → le registre `$classVersions` reste vide → lookup miss → trigger_error. Fix : toujours faire le `require_once` de l'entrypoint (idempotent), peu importe l'état de `class_exists`. Combiné au patcher des clés de registre (v1.0.16), PUC fonctionne maintenant correctement.

---

## [1.0.16] — 2026-05-22

### Fixed

- **Fatal "PUC does not support updates for plugins hosted on GitHub"** au chargement du plugin en v1.0.15. Cause : PHP-Scoper avait préfixé les clés du registre dans `load-v5p6.php` (`'Vcs\PluginUpdateChecker'` → `'Slash\Booking\Vendor\Vcs\PluginUpdateChecker'`), mais le dispatch interne de `PucFactory::buildUpdateChecker()` reconstruit la clé à runtime par concaténation de strings (`'Vcs\\' . $type . 'UpdateChecker'`) que scoper ne peut pas voir → lookup miss → `trigger_error(..., E_USER_ERROR)`. Fix : patcher scoper.inc.php qui revert les 4 clés de registre à leur forme non-préfixée. Les classes pointées par ces clés restent scopées.

**Action requise côté site cassé en v1.0.15** : remplacer le dossier `wp-content/plugins/slashbooking/` par le contenu du ZIP v1.0.16 via FTP/SSH (l'admin WP est inaccessible tant que le fatal n'est pas levé). À partir de v1.0.16, les mises à jour passent par wp-admin normalement.

---

## [1.0.15] — 2026-05-22

### Added

- **Mises à jour en 1 clic depuis wp-admin (Plugin Update Checker + GitHub Releases).** Le plugin sonde régulièrement `https://github.com/ArchSeraphin/slashbooking/releases/latest` ; WordPress affiche la notif "Mise à jour disponible" dans la liste des plugins et propose le bouton **Mettre à jour** comme pour un plugin wp.org. Aucune saisie de token côté client (repo public). PUC scopé via PHP-Scoper sous `Slash\Booking\Vendor\YahnisElsts\PluginUpdateChecker\…` pour éviter les collisions avec d'autres plugins embarquant la lib.
- **Release automatisée via GitHub Actions** (`.github/workflows/release.yml`). Push d'un tag `vX.Y.Z` → workflow build le ZIP (composer + npm + scoper + zip) → publie une release GitHub avec le ZIP en asset et la section du CHANGELOG en body. Garde-fou : le tag doit matcher `Plugin::VERSION`, sinon le workflow plante.

---

## [1.0.14] — 2026-05-22

### Changed

- **Buffer symétrique autour des événements calendrier (Google).** Les événements importés depuis le calendrier connecté reçoivent maintenant un cushion `bufferAfter` (30 min par défaut) **après** leur fin, en plus du cushion `bufferAfter` que le candidat applique déjà **avant** leur début. Résultat : un événement GCal à 14h-15h bloque les créneaux entre 13h30 et 15h30 (au lieu de 13h30 → 15h). S'applique aussi à la validation de création (`slotIsFree`) pour empêcher le bypass via POST direct.
- **Le dernier créneau peut démarrer à la fin de la plage horaire.** `SlotGenerator` itère désormais tant que `startLocal <= windowClose` (au lieu de `endLocal <= windowClose`). Si la plage se termine à 18h00 et que la durée du service est 45 min, le dernier créneau bookable démarre à 18h00 (et finit à 18h45) au lieu de 17h15.

---

## [1.0.9] — 2026-05-20

### Added

- **Éditeur de services dans le panel admin.** Nouveau tab **Services** entre Réservations et Google avec :
  - Liste de tous les services (slug, nom, durée, buffer, jours ouverts résumés, statut actif/désactivé)
  - Éditeur par service : nom, couleur, actif, durée, buffer avant/après, délai mini, horizon
  - **Édition des jours et plages horaires** : 1 toggle par jour de semaine + plages horaires multiples (matin/après-midi via inputs `time` HTML5), bouton "Ajouter une plage" et bouton supprimer par plage
- Backend : `AdminServiceController` REST (`GET /admin/services`, `GET /admin/services/{slug}`, `POST /admin/services/{slug}`) avec validation stricte des horaires (regex HH:MM, open < close, fallback sur valeur actuelle), `ServiceRepository::findAll()` et `::update()`.

---

## [1.0.8] — 2026-05-20

### Fixed

- **Slots affichaient l'ISO 8601 brut** (`2026-06-18T09:00:00+00:00`) au lieu de l'heure formatée. WordPress `get_locale()` retourne `fr_FR` (underscore) mais `Intl.DateTimeFormat` / `toLocaleTimeString` exigent BCP-47 `fr-FR` (hyphen). Sans conversion, `RangeError` était silencieusement attrapé et le fallback affichait l'ISO. Fix : `locale.replace('_', '-')`.
- **Calendrier débordait sur mobile.** Padding step réduit (20 → 14 px), gaps de grille réduits (4 → 2 px), cell font-size réduit (14 → 13 px), boutons nav réduits (36 → 32 px), légende compactée. Widget en `max-width: 100%` + marges réduites sous 520 px. Slots list passe à `minmax(88 px)` sous 480 px.

---

## [1.0.7] — 2026-05-20

### Added

- **Calendrier visuel mois-par-mois** dans le formulaire public (remplace l'input date HTML5). Navigation prev/next, légende couleurs (Disponible vert plein / Partiel vert pâle / Complet rouge / Fermé gris), respect lead time 24 h + horizon 60 jours.
- **Choix du projet inline** : `[slashbooking]` (sans paramètre) affiche les services actifs en pills (`Photovoltaïque (1h30)` / `Bornes de charge (45 min)`) et le visiteur choisit avant la date. `[slashbooking service="pv"]` continue à forcer un service (rétrocompat). `[slashbooking service="pv,irve"]` propose une liste filtrée. La durée Google Calendar suit automatiquement le service sélectionné via `Service::duration_min`.
- Step indicator dynamique (3 ou 4 étapes selon la présence du picker projet).

### Changed

- **Admin SPA background aligné WP** : `.sb-admin` passe en `background: transparent` pour hériter du gris natif de l'admin WordPress (`#f0f0f1`) au lieu de l'override `slate-50` plus blanc. Les cards conservent leur fond blanc pour le contraste.

---

## [1.0.6] — 2026-05-20

### Changed

- **Refonte design pro du panel admin et du formulaire public.** Système de tokens unifié (palette blue/emerald/orange, neutres slate, radius+shadow scale, spacing 4/8 px), system-ui stack pour zéro dépendance externe RGPD-safe.
- **Admin SPA** : header avec logo + titre + version, onglets en pills propres, KPI dashboard 4 cards en tête de la liste réservations (Total / À valider / Confirmés / À venir), table custom avec hover + status badges colorés (dot + pill), empty state illustré, boutons WP-components stylés.
- **Formulaire public** : header trust signals (sécurité données + réponse 24h), step indicator vivant 1/2/3, cards séparées par étape avec hint contextuel, date picker stylé, slots cards avec hover+focus+selected, champs avec labels au-dessus + placeholders + autocomplete sémantique (mobile keyboard), consent box dédiée, CTA orange XXL avec spinner inline, écran de succès illustré, scroll automatique du formulaire à la sélection.
- **Accessibility** : focus rings 2-3 px sur tous les controls, `prefers-reduced-motion` respecté, `role="progressbar"` + `aria-live` + `htmlFor` partout, autocomplete + types sémantiques (`tel`, `email`).
- Design system persisté dans `design-system/slashbooking/MASTER.md` pour future référence.

---

## [1.0.5] — 2026-05-20

### Fixed

- **Shortcode `[slashbooking]` ne s'affichait pas.** Deux bugs cumulés :
  1. `scoper.inc.php` finder filtre `*.php` uniquement → `src/PublicFront/assets/booking.{js,css}` étaient absents du ZIP. Fix : `bin/build-release.sh` copie maintenant ce répertoire depuis l'arbre non-scopé.
  2. `Shortcode::maybeEnqueue()` lisait un flag global set dans `render()`, mais `wp_enqueue_scripts` fire AVANT que les shortcodes ne soient rendus → enqueue never triggered. Fix : `Shortcode::render()` appelle `wp_enqueue_script` / `wp_enqueue_style` directement (WP les queue et imprime en footer). Bug latent depuis Plan 1.

---

## [1.0.4] — 2026-05-20

### Fixed

- **404 sur toutes les requêtes REST du SPA.** `setupApi()` ajoutait un `createRootURLMiddleware` pointant vers `wp-json/slashbooking/v1/`. Notre middleware était exécuté en premier (LIFO), construisait l'URL correcte, mais laissait `options.path` intact. Le rootURL middleware natif de WP s'exécutait ensuite, voyait `path` toujours string, et **réécrivait l'URL** en `wp-json/<path>` — sans le namespace. Fix : on n'override plus le rootURL ; on injecte simplement `slashbooking/v1/` dans le `path` avant que WP construise l'URL finale. Bug latent depuis Plan 2.

---

## [1.0.3] — 2026-05-20

### Fixed

- **Page admin SPA blanche.** `Assets::enqueue()` cherchait `assets/dist/admin.asset.php` / `admin.js` / `admin.css`, mais `wp-scripts` produit `index.jsx.asset.php` / `index.jsx.js` / `index.jsx.css` (filename dérivé du fichier d'entry `src/Admin/react-app/src/index.jsx`). Le check `is_file()` échouait silencieusement → aucun JS/CSS enqueué → div mount point vide. Bug latent depuis Plan 2.

---

## [1.0.2] — 2026-05-20

### Fixed

- **Activation fatal — 2e occurrence.** `Plugin::register()` appelait `rest_url()` au plugin file load (depuis `wp-settings.php`, avant que `$wp_rewrite` ne soit initialisé) → `Error: Call to a member function using_index_permalinks() on null`. Fix : `UrlBuilder` reçoit maintenant une `Closure` qui résout l'URL paresseusement à la première utilisation (= quand un `BookingNotifier` callback fire). Bug latent depuis Plan 2.

---

## [1.0.1] — 2026-05-20

### Fixed

- **Activation fatal sur fresh install.** `Plugin::register()` instanciait `DecisionTokenSigner` avec un secret vide avant que `register_activation_hook` n'ait pu seeder l'option `sb_decision_secret` → `InvalidArgumentException: Decision secret must be at least 16 characters`. Fix : `Activator::ensureDecisionSecret()` est maintenant publique et appelée en tête de `Plugin::register()` (idempotent). Bug latent depuis Plan 2.

---

## [1.0.0] — 2026-05-20

Première release stable. Périmètre V1 fermé selon `docs/superpowers/specs/2026-05-19-slashbooking-design.md`.

### Added — Plan 5 : Polish V1

- Éditeur de templates e-mail dans le dashboard admin (CodeMirror 6 + preview live + insertion de tag + envoi d'un test + restauration du défaut) pour les 6 événements (`booking.pending.client/admin`, `booking.confirmed.client`, `booking.rejected.client`, `booking.cancelled.client`, `booking.reminder.client`).
- Internationalisation complète : `languages/slashbooking.pot` généré, traduction `fr_FR` fournie.
- Conformité RGPD :
  - Privacy Exporter (`wp_privacy_personal_data_exporters`) — exporte tous les bookings matchant un e-mail.
  - Privacy Eraser (`wp_privacy_personal_data_erasers`) — anonymise via SHA-256 + `@anon.invalid`, conserve les agrégats.
  - Masquage e-mails dans `sync_log` (`a***@d***`).
  - Option `sb_legal_page_id` pour lien "Mentions légales" sous case de consentement.
  - Cron mensuel `sb/purge_old_bookings` (rétention par défaut 3 ans après `ends_at_utc`).
- Isolation des dépendances vendor via PHP-Scoper (`Slash\Booking\Vendor\Google\…`, etc.) — élimine le risque de collision avec d'autres plugins WordPress.
- Script `bin/build-release.sh` produit un ZIP de release reproductible (composer no-dev + npm build + scoping + autoload classmap + checksum SHA-256).
- Documentation `README.md` complète (walkthrough Google Cloud Console, troubleshooting).

### Added — Plan 4 : Webhook + pull Google → WP

- Push notifications Google : `WatchChannelManager` (start/stop/renew) + endpoint REST `POST /google/webhook` (vérif HMAC `X-Goog-Channel-Token`).
- Pull incrémental via `events.list` + `syncToken` : `SyncEngine` + handler Action Scheduler `sb/google_pull`.
- Reflection : ignore les events GCal créés par notre propre push (Plan 3) via `BookingRepository::findByGoogleEventId`.
- Cron `sb/watch_renew_check` (quotidien) + `sb/google_pull_all` (15 min fallback).
- Diagnostics étendus : SPA `GooglePage` montre statut watch, dernier sync, sync token ; `wp slashbooking doctor` étendu.
- Upgrade PHPStan 1.x → 2.x avec `treatPhpDocTypesAsCertain: false`.

### Added — Plan 3 : Google OAuth + push WP → GCal

- OAuth 2.0 utilisateur (refresh token chiffré via `sodium_crypto_secretbox`).
- Push WP → GCal via Action Scheduler `sb/push_gcal_event` (create / update / delete selon statut).
- Code couleur GCal : orange `colorId=6` (pending), vert `colorId=10` (confirmed), delete (rejected/cancelled).
- Journal de synchronisation `wp_sb_sync_log` (cron quotidien `sb_purge_sync_log` 30j).
- CLI `wp slashbooking doctor` (état OAuth + probe create/delete event).
- SPA admin : `GooglePage` (Configuration OAuth + Google Calendar) + `SyncLogPage`.

### Added — Plan 2 : Notifications e-mail + validation admin

- 6 templates HTML personnalisables (`wp_sb_mail_templates`) + tags `{{...}}` + fallback texte auto via `wp_strip_all_tags`.
- Pièce jointe `.ics` RFC 5545 sur l'e-mail de confirmation.
- Reminder J-1 (cron quotidien `sb_send_daily_reminders` à 10h00 site TZ).
- Validation admin : boutons HMAC dans l'e-mail (72h, idempotent) + dashboard React minimal.
- Annulation client via lien HMAC dans e-mail de confirmation.
- Capabilities WP : `slashbooking_manage`, `slashbooking_view`.

### Added — Plan 1 : Fondations

- Architecture modulaire (Domain / Persistence / Availability / Booking / Http / PublicFront / Cli).
- Modèle de données : 6 tables `wp_sb_*` (`services`, `bookings`, `busy_blocks`, `google_accounts`, `sync_log`, `mail_templates`).
- 2 services seed à l'activation (`pv` 90min, `irve` 45min).
- REST API publique : `GET /services`, `GET /availability`, `POST /bookings`.
- Bloc Gutenberg + shortcode `[slashbooking service="pv|irve"]`.
- Anti-bot : honeypot + délai min + rate-limit transient.
- Buffer 30 min + délai 24h + horizon 60 jours.

---

[1.0.0]: https://github.com/trinity/booking/releases/tag/v1.0.0
