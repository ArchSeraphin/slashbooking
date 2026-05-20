# trinity-booking — Plan 5 : Polish V1 (templates editor + RGPD + i18n + packaging)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fermer la V1 : éditeur de templates e-mail (CodeMirror + preview + tags + test + restore) ; isolation des dépendances vendor via PHP-Scoper ; i18n complet (POT + fr_FR) ; conformité RGPD (exporters + erasers + mentions légales + purge bookings) ; documentation finale et packaging ZIP reproductible. À la fin du plan, le plugin est diffusable.

**Architecture:**

- **Build/packaging.** Ajout de `humbug/php-scoper` en dev dep, fichier `scoper.inc.php` avec prefix `Trinity\Booking\Vendor`. Script `bin/build-release.sh` qui produit un ZIP `trinity-booking-<version>.zip` (composer no-dev + npm build + scoping vendor + src + checksum). Le code source `src/` n'est jamais modifié manuellement — c'est PHP-Scoper qui réécrit `Google\` et `GuzzleHttp\` en `Trinity\Booking\Vendor\Google\` / `Trinity\Booking\Vendor\GuzzleHttp\` à la phase build.
- **i18n.** `make-pot` via `wp-cli` génère `languages/trinity-booking.pot`. On stub `trinity-booking-fr_FR.po/.mo` (la majorité des chaînes admin est déjà en français : on traduit les chaînes _techniques_ et de fallback EN). `Plugin::register()` charge `load_plugin_textdomain('trinity-booking', false, 'trinity-booking/languages')`.
- **RGPD.** Deux nouvelles classes `Privacy/BookingExporter` et `Privacy/BookingEraser`, branchées sur les hooks `wp_privacy_personal_data_exporters` / `_erasers`. Eraser anonymise via hash SHA-256 court du `customer_email` (conserve l'agrégat). Nouvelle option WP `tb_legal_page_id` (page de mentions légales) exposée par REST et rendue dans le shortcode public via lien sous la case de consentement. Sync log : vérification du masquage e-mail (`a***@d***`) déjà mis en place Plan 3, audit + correctif si besoin. Nouveau cron `tb/purge_old_bookings` mensuel (rétention par défaut 3 ans après `ends_at_utc`, configurable via option `tb_booking_retention_days`).
- **Templates editor.** Backend : `Http/AdminMailTemplateController` (GET liste, GET/POST/DELETE par event_key, POST preview, POST test) + `Http/TagRegistryController` (GET catégories + tags). Frontend : `TemplatesPage.jsx` (liste des 6 templates avec badges custom/default), `TemplateEditor.jsx` (split-pane : CodeMirror HTML à gauche, preview à droite, bouton "Insérer un tag" en dropdown groupé, bouton "Envoyer un test", bouton "Restaurer le défaut"). Le preview est rendu **côté serveur** via le contrôleur (POST `/preview`) pour réutiliser `TemplateRenderer` et garantir parité d'exécution avec le vrai envoi.
- **Documentation & version.** `README.md` final avec walkthrough Google Cloud Console + capture d'écran SPA (placeholder) + section troubleshooting. `CHANGELOG.md` rétrospectif sur les commits Plans 1–5. Version `Plugin::VERSION` bump `0.1.0-dev → 1.0.0-rc1` au démarrage puis `→ 1.0.0` à la fin du plan, header `trinity-booking.php` synchronisé.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, `humbug/php-scoper ^0.18` (dev), `wp-cli` (dev local), `@uiw/react-codemirror ^4.x` + `@codemirror/lang-html ^6.x` (SPA), Action Scheduler v3.x (pour le cron de purge), PHPStan 2.x, PHPUnit 10 + Brain Monkey.

**Spec source:** `docs/superpowers/specs/2026-05-19-trinity-booking-design.md`, sections 2 (templates + RGPD), 8 (templates e-mail + tags + éditeur admin), 9 (sécurité), 10 (RGPD complet), 13 (i18n), 14 (installation + packaging), 15 (risque #1 = conflit namespaces vendors), 17 (étape 5 = polish, i18n, RGPD, tests E2E, packaging).

---

## Préambule — Concepts clés pour l'ingénieur

À lire avant d'attaquer les tâches. Beaucoup de décisions tooling sont contre-intuitives ; les ignorer reproduira des erreurs déjà faites Plan 3-4.

1. **PHP-Scoper réécrit le namespace `Google\…` en `Trinity\Booking\Vendor\Google\…` à la phase build.** Le code source `src/` continue d'écrire `use Google\Client`. À la phase release, PHP-Scoper passe sur **tout le projet** (`src/` + `vendor/`) et produit une copie réécrite dans `build/dist/`. Notre code `vendor/` dev reste original — ça nous évite de devoir maintenir deux chaînes d'imports. Les tests unitaires tournent contre `vendor/` non-prefixé (puisqu'on n'a qu'une seule copie de Google sur la machine de dev).

2. **`scoper.inc.php` est le centre de gravité du packaging.** Il déclare le `prefix`, les `finders` (quels fichiers scoper), les `exclude-namespaces` (WordPress, PSR-3, etc. doivent rester globaux), les `exclude-classes` (`wpdb` etc.), les `exclude-functions` (`__`, `esc_html`…), et un `patchers` callback pour les cas spéciaux du SDK Google (ex. `Google\Service\Calendar::SCOPES` constant string). Référence pratique : [https://github.com/humbug/php-scoper#configuration](https://github.com/humbug/php-scoper#configuration).

3. **Le ZIP de release ne contient PAS `vendor/` non-scopé.** Il contient `vendor-prefixed/` produit par scoper + `assets/dist/` (build npm) + `src/` réécrit par scoper + `languages/` + `composer.json` (sans `require-dev`, pour info) + `trinity-booking.php` + `uninstall.php` + `README.md` + `CHANGELOG.md`. Pas de `node_modules/`, pas de `tests/`, pas de `bin/`, pas de `docs/`. Le `.gitignore` à la racine du plugin liste ces exclusions.

4. **i18n WordPress = 3 niveaux.** `__('texte', 'trinity-booking')` retourne la traduction (texte + text-domain). `_x('texte', 'context', 'trinity-booking')` pour les chaînes ambiguës (ex. "post" verbe vs nom). `_n('singulier', 'pluriel', $count, 'trinity-booking')` pour le pluriel. `esc_html__()` / `esc_attr__()` combinent traduction + échappement. **Notre code utilise déjà `__` partout** — ce plan n'ajoute pas de traductions, il génère le POT et le PO.

5. **`wp i18n make-pot` est la commande de référence pour générer le POT.** Elle scanne `src/` + le JSX buildé (assets/dist) ET les fichiers JSX sources si on lui passe `--include="src/Admin/react-app/**.jsx"`. Pour récupérer correctement les chaînes JS, on lui demande aussi `--allow-root` en local Docker. Output : `languages/trinity-booking.pot` UTF-8 avec headers `Project-Id-Version`, `Last-Translator`, etc.

6. **`fr_FR.po` peut être quasi-identique au POT.** Notre interface admin est déjà rédigée en français (cf. `__('Réservations', 'trinity-booking')` dans `App.jsx`). Le POT contient ces chaînes comme `msgid` ; `fr_FR.po` les recopie comme `msgstr` (identité). C'est légal et utile : ça « confirme » la traduction française et permet de désynchroniser un jour si on change le code source en anglais.

7. **`load_plugin_textdomain` au hook `init`.** Le bon hook est `init` (priorité 0 par défaut). On enregistre via `add_action('init', ...)` dans `Plugin::register()`. Plus tôt (ex. `plugins_loaded`) ne fonctionne pas pour les chaînes utilisées dans des hooks `init` eux-mêmes. Le path est relatif à `WP_PLUGIN_DIR` : `trinity-booking/languages`.

8. **Privacy Exporter WordPress = fonction qui retourne `[ 'data' => [...], 'done' => bool ]`.** Voir `wp-admin/includes/class-wp-personal-data-exporter.php`. On enregistre via filter `wp_privacy_personal_data_exporters`. L'admin va dans **Outils → Exporter les données personnelles**, saisit un e-mail, WP appelle notre callback avec `( $email, $page = 1 )`. On retourne tous les bookings matchant `customer_email`, formatés en `group_id`, `group_label`, `item_id`, `data` (paires `name / value`).

9. **Privacy Eraser WordPress = anonymisation.** Hook `wp_privacy_personal_data_erasers`. Callback `( $email, $page = 1 )` retourne `[ 'items_removed', 'items_retained', 'messages', 'done' ]`. **Important :** "removed" ≠ DELETE physique. On **anonymise** (`customer_name='Anonyme'`, `customer_email = sha256(email)`, `customer_phone='000000000'`, `customer_address=''`, `notes=''`, `customer_meta='{}'`) pour conserver les agrégats (CA, dispo, historique). Le hash garantit l'idempotence — re-eraser sur le même e-mail retombe sur la même ligne.

10. **`customer_email` masqué dans `sync_log` = `a***@d***` (premier caractère + `***` + premier caractère domaine + `***`).** Spec §10. Audit Plan 5 : vérifier que `BookingNotifier`, `PushEventJob`, `SyncEngine` n'insèrent jamais d'e-mail brut dans le payload du sync log. Si trouvé → corriger via un helper `Privacy\EmailMasker::mask($email): string`.

11. **Rétention bookings = 3 ans par défaut, configurable.** Cron mensuel `tb/purge_old_bookings` (run le 1er de chaque mois à 03:30 timezone site). Sélectionne `bookings WHERE ends_at_utc < NOW() - INTERVAL X DAY AND status IN ('completed','cancelled','rejected')`. **N'efface pas les `confirmed` futurs**. Option WP `tb_booking_retention_days` (défaut 1095). Hard delete (pas anonymisation — c'est déjà rétention légale RGPD : on supprime).

12. **CodeMirror 6 via `@uiw/react-codemirror`.** Package wrapper React stable. `import CodeMirror from '@uiw/react-codemirror'; import { html } from '@codemirror/lang-html';`. Composant : `<CodeMirror value={html} extensions={[html()]} onChange={setHtml} />`. Hauteur réglée via prop `height="400px"`. **Important :** ces deux paquets pèsent ~80 KB minified — c'est acceptable pour de l'admin (pas le front public). Le bundle SPA passe d'~80 KB à ~160 KB.

13. **Preview rendu côté serveur, pas côté client.** Le client poste `{ html_body, subject }` au backend (`POST /admin/mail-templates/:event_key/preview`), le backend instancie `TemplateRenderer` avec un dataset factice (`BookingContext::fromBooking` sur un booking PHP construit en mémoire, jamais persisté), retourne `{ subject, html }`. Avantages : (a) parité 100% avec le vrai envoi, (b) pas besoin de réimplémenter `{{tag}}` regex en JS, (c) sécurité (`wp_kses_post` côté retour preview pour éviter XSS dans iframe). L'iframe affiche `srcDoc={html}` avec `sandbox="allow-same-origin"` (pas `allow-scripts`).

14. **Bouton "Envoyer un test" utilise l'e-mail courant de l'utilisateur WP.** Pas de champ de saisie. `wp_get_current_user()->user_email`. On loggue dans `sync_log` (`direction=internal, action=template_test`) pour audit. Mail envoyé via `MailDispatcher::sendRaw($to, $subject, $htmlBody, $textBody)` — méthode existante.

15. **Bouton "Restaurer le défaut" = DELETE row.** Quand l'utilisateur clique, on appelle `DELETE /admin/mail-templates/:event_key`. `MailTemplateRepository::delete()` retire la ligne ; `getOrDefault()` retombe sur `DefaultTemplates::all()` → la liste réaffiche `is_custom: false`. **Pas de soft-delete** — la spec autorise le full restore (§8.3).

16. **TagRegistry exposé en REST.** Le composant React a besoin de lister les tags par catégorie avec leur `description` pour le dropdown. On expose `GET /admin/tags` qui retourne `{ groups: [{ category, tags: [{ name, description, raw }] }] }`. Le composant React groupe automatiquement.

17. **Pas d'ajout de tag en V1.** L'utilisateur ne peut pas inventer un nouveau tag custom : le `TagRegistry` est codé en dur (`buildTags()`). Un tag inconnu dans le template reste tel quel et est loggué par `TemplateRenderer`. Si V2 demande des tags dynamiques, on ajoutera un store option.

18. **CodeMirror n'enregistre rien automatiquement.** Bouton "Enregistrer" explicite avec spinner. Modification trackée via un state `isDirty` ; navigation hors page sans save → `window.confirm('Modifications non sauvegardées, quitter ?')`. Pas d'autosave V1.

19. **Le SPA `@wordpress/scripts` build est déjà câblé.** Notre `package.json` script `build` produit `assets/dist/index.jsx.{js,css}`. Ajouter `@uiw/react-codemirror` et `@codemirror/lang-html` se fait via `npm install`. Le webpack `@wordpress/scripts` les bundle automatiquement.

20. **Ordre d'exécution des tâches.** PHP-Scoper en premier (le plus risqué, peut casser tout) → on garde la suite cohérente. i18n ensuite (impact léger, mais doit précéder les libellés ajoutés par RGPD/templates). RGPD au milieu (touche le domaine, infra). Templates editor (le plus long, mais isolé : backend + frontend). Documentation finale + packaging à la fin.

21. **PHPStan 2.x reste à `treatPhpDocTypesAsCertain: false`.** On ne change pas la config héritée du Plan 4. Si scoper introduit des classes prefixées que PHPStan ne reconnaît pas, on ajoute `excludePaths` ou `ignoreErrors` ciblés — mais en pratique PHPStan tourne sur `src/` (non-scopé) et `vendor/szepeviktor/phpstan-wordpress` (déjà non-scopé) donc rien à changer.

22. **Pas de modification du modèle de données SQL en Plan 5.** Aucune migration. Le schéma `wp_tb_mail_templates` existe déjà (Plan 2). Si on doit toucher le schéma, c'est qu'on est en train de prendre une mauvaise décision.

23. **Capability requise pour tous les nouveaux endpoints `/admin/mail-templates/*` et `/admin/tags` : `trinity_booking_manage`.** Comme Plan 3/4. Nonce vérifié automatiquement par `@wordpress/api-fetch` (middleware déjà câblé `setupApi()`).

24. **Le ZIP de release est versionné dans le nom.** `trinity-booking-1.0.0.zip`. Pas de `-rc1`/`-rc2` traînant en `Plugin::VERSION` à la fin du plan. La toute dernière tâche du plan bump à `1.0.0` (sans suffixe) ; juste avant elle, on est en `1.0.0-rc1`.

25. **Pas de tag git automatique.** Le tag `v1.0.0` est manuel après validation finale. Le plan crée le commit "release: v1.0.0" mais ne lance pas `git tag`. L'utilisateur (Nicolas) le fait depuis la CLI quand il valide.

---

## File Structure (Plan 5 scope)

```
plugins-booking/
├── trinity-booking.php                          # MODIFY — version 0.1.0-dev → 1.0.0-rc1 → 1.0.0
├── composer.json                                # MODIFY — add humbug/php-scoper ^0.18 dev dep
├── composer.lock                                # AUTO — composer update
├── package.json                                 # MODIFY — add @uiw/react-codemirror + @codemirror/lang-html
├── package-lock.json                            # AUTO — npm install
├── scoper.inc.php                               # CREATE — config PHP-Scoper (prefix, finders, exclusions)
├── README.md                                    # MODIFY — walkthrough complet + screenshots placeholder + troubleshooting
├── CHANGELOG.md                                 # CREATE — historique Plans 1-5
├── .distignore                                  # CREATE — liste des paths à exclure du ZIP (pour future CI WP.org éventuelle)
├── bin/
│   └── build-release.sh                         # CREATE — script bash : composer + npm + scoper + zip
├── languages/
│   ├── trinity-booking.pot                      # CREATE — généré par wp i18n make-pot
│   ├── trinity-booking-fr_FR.po                 # CREATE — traduction française (souvent identité)
│   └── trinity-booking-fr_FR.mo                 # AUTO — généré par msgfmt
├── src/
│   ├── Plugin.php                               # MODIFY — load_plugin_textdomain + register Privacy + register AdminMailTemplate routes + register cron purge
│   ├── Activator.php                            # MODIFY — schedule tb/purge_old_bookings cron mensuel
│   ├── Deactivator.php                          # MODIFY — unschedule tb/purge_old_bookings
│   ├── Privacy/
│   │   ├── BookingExporter.php                  # CREATE — wp_privacy_personal_data_exporters
│   │   ├── BookingEraser.php                    # CREATE — wp_privacy_personal_data_erasers (anonymisation)
│   │   ├── EmailMasker.php                      # CREATE — helper masquage e-mail dans logs ("a***@d***")
│   │   └── BookingRetentionPurger.php           # CREATE — handler cron tb/purge_old_bookings (3y default)
│   ├── Persistence/
│   │   └── BookingRepository.php                # MODIFY — anonymizeByEmail + deleteOlderThan helpers
│   ├── Notifications/
│   │   └── MailDispatcher.php                   # MODIFY (mineur) — expose sendRaw($to, $subj, $html, $text) si pas déjà public
│   ├── Http/
│   │   ├── AdminMailTemplateController.php      # CREATE — REST GET liste/single + POST save + DELETE restore + POST preview + POST test
│   │   ├── TagRegistryController.php            # CREATE — REST GET /admin/tags grouped
│   │   ├── PublicBookingController.php          # MODIFY — exposer "legal_url" dans GET /services (utile pour shortcode lien mentions)
│   │   └── RestRouter.php                       # MODIFY — wire AdminMailTemplateController + TagRegistryController
│   ├── PublicFront/
│   │   ├── Shortcode.php                        # MODIFY — wp_localize_script ajoute legal_url depuis option
│   │   └── assets/
│   │       └── booking.js                       # MODIFY — afficher lien "Mentions légales" sous case consentement si fourni
│   └── Admin/
│       ├── AdminMenu.php                        # (inchangé)
│       └── react-app/
│           └── src/
│               ├── App.jsx                       # MODIFY — ajouter onglet "Templates"
│               ├── api.js                        # MODIFY — listMailTemplates / saveMailTemplate / etc.
│               ├── TemplatesPage.jsx             # CREATE — liste 6 templates + sélecteur
│               └── TemplateEditor.jsx            # CREATE — split-pane CodeMirror + preview + tags + test + restore
├── tests/
│   ├── Unit/
│   │   ├── Privacy/
│   │   │   ├── BookingExporterTest.php          # CREATE
│   │   │   ├── BookingEraserTest.php            # CREATE
│   │   │   ├── EmailMaskerTest.php              # CREATE
│   │   │   └── BookingRetentionPurgerTest.php   # CREATE
│   │   └── …                                    # tests existants inchangés
│   └── Integration/
│       ├── AdminMailTemplateControllerTest.php   # CREATE — couvre GET/POST/DELETE/preview/test
│       └── TagRegistryControllerTest.php         # CREATE
└── docs/superpowers/plans/
    └── 2026-05-20-trinity-booking-plan-5-polish-packaging.md   # ce fichier
```

Récap fichiers créés : **15** ; fichiers modifiés : **12** ; tests ajoutés : **6**.

---

## Task 1 : Bump version `0.1.0-dev` → `1.0.0-rc1`

On commence par marquer l'entrée dans le cycle release-candidate. La constante `Plugin::VERSION` est référencée par les `wp_enqueue_*` (cache-busting CSS/JS), par les User-Agent HTTP, par le header plugin. Tout en un commit.

**Files:**
- Modify: `trinity-booking.php`
- Modify: `src/Plugin.php`
- Modify: `package.json`
- Modify: `README.md`

- [ ] **Step 1 : Modifier `trinity-booking.php`**

Remplacer la ligne `Version: 0.1.0-dev` :

```php
 * Version:           1.0.0-rc1
```

- [ ] **Step 2 : Modifier `src/Plugin.php`**

Remplacer la constante :

```php
public const VERSION = '1.0.0-rc1';
```

- [ ] **Step 3 : Modifier `package.json`**

Bump le `version` de `0.2.0` à `1.0.0-rc.1` (npm semver ne tolère pas `-rc1`, il faut `-rc.1` avec point) :

```json
"version": "1.0.0-rc.1",
```

- [ ] **Step 4 : Mettre à jour le statut dans `README.md`**

Remplacer la section "Statut" en haut du fichier :

```markdown
## Statut

- ✅ **Plan 1** — fondations + parcours de réservation public minimal fonctionnel.
- ✅ **Plan 2** — notifications e-mail (6 events + templates + .ics) + validation admin (HMAC e-mail + dashboard React).
- ✅ **Plan 3** — Google OAuth + push WP → GCal via Action Scheduler (chiffrement sodium, journal de sync, `wp trinity-booking doctor`).
- ✅ **Plan 4** — webhook + pull GCal → WP (SyncEngine + WatchChannelManager + crons + SPA diagnostics).
- 🚧 **Plan 5** (en cours) — éditeur de templates + RGPD + i18n + packaging V1.

Version courante : **1.0.0-rc1**.
```

- [ ] **Step 5 : Vérifier l'autoload et la suite de tests**

```bash
composer dump-autoload
composer test
composer stan
```

Attendu : 117 tests OK + PHPStan clean (rien ne dépend littéralement de `0.1.0-dev`, mais on vérifie qu'aucun test ne capture la version en hardcoded).

- [ ] **Step 6 : Commit**

```bash
git add trinity-booking.php src/Plugin.php package.json README.md
git commit -m "chore: bump version to 1.0.0-rc1 — start Plan 5"
```

---

## Task 2 : PHP-Scoper — installation + `scoper.inc.php`

On ajoute la dépendance dev et le fichier de configuration. Pas encore d'exécution ni d'intégration au build : juste la base.

**Files:**
- Modify: `composer.json`
- Create: `scoper.inc.php`

- [ ] **Step 1 : Ajouter la dev dependency**

```bash
composer require --dev humbug/php-scoper:^0.18
```

Attendu : `composer.json` mis à jour, `composer.lock` régénéré, `vendor/bin/php-scoper` installé.

- [ ] **Step 2 : Créer `scoper.inc.php` à la racine du plugin**

```php
<?php
declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Trinity\\Booking\\Vendor',

    'finders' => [
        // 1) Tout le code source du plugin.
        Finder::create()
            ->files()
            ->in('src')
            ->name('*.php'),

        // 2) Composer vendors qu'on veut isoler (Google API client + dépendances transitives).
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.json|composer\\.lock/')
            ->exclude([
                'doc',
                'test',
                'test_old',
                'tests',
                'Tests',
                'vendor-bin',
            ])
            ->in('vendor/google')
            ->in('vendor/guzzlehttp')
            ->in('vendor/psr')
            ->in('vendor/firebase')
            ->in('vendor/monolog')
            ->in('vendor/paragonie')
            ->in('vendor/phpseclib')
            ->in('vendor/ralouphie')
            ->in('vendor/symfony')
            ->name('*.php'),

        // 3) Composer autoloader files (pour que le build régénère un autoload classmap propre).
        Finder::create()
            ->append([
                'vendor/google/apiclient/composer.json',
                'vendor/guzzlehttp/guzzle/composer.json',
            ]),
    ],

    // Ne PAS prefixer ces namespaces : ils restent globaux.
    'exclude-namespaces' => [
        // WordPress n'a pas de namespace formel, mais on protège les classes globales connues.
        'Trinity\\Booking',          // notre code à nous, jamais préfixé
        'PHPUnit',
        'Composer',
        'Symfony\\Polyfill',         // polyfills doivent rester globaux
    ],

    // Classes globales WordPress / PHP à ne pas réécrire.
    'exclude-classes' => [
        'wpdb',
        'WP_Error',
        'WP_REST_Request',
        'WP_REST_Response',
        'WP_REST_Server',
        'WP_User',
        'WP_Query',
        'WP_Post',
        'ActionScheduler',
        'ActionScheduler_DBStoreMigrator',
        'WP_CLI',
        '/^Google_/',                // anciens alias de la lib Google (Google_Client, etc.)
    ],

    // Fonctions globales : __, esc_html, get_option, etc. Aucune ne doit être prefixée.
    'exclude-functions' => [
        '/^wp_/',
        '/^get_/',
        '/^add_/',
        '/^update_/',
        '/^delete_/',
        '/^do_action/',
        '/^apply_filters/',
        '/^esc_/',
        '/^sanitize_/',
        '/^is_/',
        '/^current_/',
        '/^current_user_can/',
        '/^register_/',
        '/^rest_/',
        '/^plugin_/',
        '__',
        '_e',
        '_x',
        '_n',
        '_nx',
        'load_plugin_textdomain',
        'as_schedule_single_action',
        'as_unschedule_all_actions',
        'as_next_scheduled_action',
    ],

    // Constantes globales WP à exclure.
    'exclude-constants' => [
        '/^WP_/',
        'ABSPATH',
        'WPINC',
        'ARRAY_A',
        'ARRAY_N',
        'OBJECT',
        'OBJECT_K',
    ],

    // Patchers : interventions ciblées pour les libs qui font des choses non-standard.
    'patchers' => [
        // Google\Client utilise `class_exists('GuzzleHttp\\Client')` en string : prefixons-la.
        static function (string $filePath, string $prefix, string $content): string {
            if (str_contains($filePath, 'vendor/google/apiclient/src/Client.php')) {
                $content = str_replace(
                    "class_exists('GuzzleHttp\\\\Client')",
                    "class_exists('{$prefix}\\\\GuzzleHttp\\\\Client')",
                    $content,
                );
            }
            return $content;
        },
    ],
];
```

- [ ] **Step 3 : Vérifier que `php-scoper` se lance**

```bash
vendor/bin/php-scoper --version
```

Attendu : `Humbug PHP-Scoper version 0.18.x` (ou supérieur).

- [ ] **Step 4 : Dry-run de scoping (sans modifier le repo)**

```bash
vendor/bin/php-scoper add-prefix --output-dir=/tmp/scoper-dryrun --no-interaction --force
```

Le dryrun sort un répertoire `/tmp/scoper-dryrun/` avec `src/` + sous-arbres `vendor/`. Vérifier rapidement :

```bash
head -20 /tmp/scoper-dryrun/src/Google/PushEventJob.php
```

Attendu : ligne `use Trinity\Booking\Vendor\Google\Client;` au lieu de `use Google\Client;`.

```bash
head -20 /tmp/scoper-dryrun/vendor/google/apiclient/src/Client.php
```

Attendu : `namespace Trinity\Booking\Vendor\Google;`.

Si le dryrun produit ces deux résultats → la config est correcte. Sinon, ajuster les `finders` ou les `patchers` jusqu'à obtention.

- [ ] **Step 5 : Nettoyer le dryrun**

```bash
rm -rf /tmp/scoper-dryrun
```

- [ ] **Step 6 : Commit**

```bash
git add composer.json composer.lock scoper.inc.php
git commit -m "build: add humbug/php-scoper config + dev dependency"
```

---

## Task 3 : Script `bin/build-release.sh`

Pipeline complet de packaging : composer no-dev + npm build + scoping + dump-autoload + ZIP + checksum SHA-256.

**Files:**
- Create: `bin/build-release.sh`
- Create: `.distignore`

- [ ] **Step 1 : Créer `.distignore` à la racine**

```
# Liste des paths exclus du ZIP de release.
.distignore
.editorconfig
.git
.github
.gitignore
.phpunit.cache
.vscode
bin
build
docs
node_modules
package.json
package-lock.json
phpcs.xml.dist
phpstan.neon
phpunit.xml
scoper.inc.php
src/Admin/react-app
tests
vendor-bin
README.dev.md
*.log
.DS_Store
```

Note : `src/Admin/react-app` (sources JSX) est exclu — le ZIP n'embarque que `assets/dist/` (build webpack).

- [ ] **Step 2 : Créer `bin/build-release.sh`**

```bash
#!/usr/bin/env bash
# Build a distribution ZIP for trinity-booking.
# Usage: bin/build-release.sh [version]
#        version defaults to the value read from src/Plugin.php

set -euo pipefail

PLUGIN_SLUG="trinity-booking"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
SCOPED_DIR="${BUILD_DIR}/scoped"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    VERSION=$(grep -E "^[[:space:]]*public const VERSION" "${ROOT_DIR}/src/Plugin.php" \
        | sed -E "s/.*'([^']+)'.*/\1/")
fi
ZIP_PATH="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "→ Building ${PLUGIN_SLUG} v${VERSION}"

# 1. Clean previous build
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING_DIR}" "${SCOPED_DIR}"

# 2. Install Composer prod dependencies (without dev) into vendor/
echo "→ composer install --no-dev (production deps)"
(cd "${ROOT_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)

# 3. Build npm assets (production webpack)
echo "→ npm run build (SPA assets)"
(cd "${ROOT_DIR}" && npm ci --silent && npm run build --silent)

# 4. Run PHP-Scoper to produce scoped src/ + vendor/
echo "→ php-scoper (prefix Trinity\\Booking\\Vendor)"
# Re-install dev to get php-scoper binary
(cd "${ROOT_DIR}" && composer install --quiet)
(cd "${ROOT_DIR}" && vendor/bin/php-scoper add-prefix \
    --config=scoper.inc.php \
    --output-dir="${SCOPED_DIR}" \
    --force \
    --no-interaction \
    --quiet)

# 5. Regenerate Composer autoload classmap inside the scoped tree
echo "→ composer dump-autoload (scoped, classmap-authoritative)"
# Copy a minimal composer.json into scoped dir for dump-autoload to work
cp "${ROOT_DIR}/composer.json" "${SCOPED_DIR}/composer.json"
# Strip require-dev (we don't ship test deps)
php -r "
\$j = json_decode(file_get_contents('${SCOPED_DIR}/composer.json'), true);
unset(\$j['require-dev'], \$j['autoload-dev'], \$j['scripts']);
file_put_contents('${SCOPED_DIR}/composer.json', json_encode(\$j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
"
(cd "${SCOPED_DIR}" && composer dump-autoload --classmap-authoritative --no-interaction --quiet)

# 6. Stage final tree
echo "→ staging files into ${STAGING_DIR}"
cp -R "${SCOPED_DIR}/src" "${STAGING_DIR}/src"
cp -R "${SCOPED_DIR}/vendor" "${STAGING_DIR}/vendor"
cp "${ROOT_DIR}/trinity-booking.php" "${STAGING_DIR}/trinity-booking.php"
cp "${ROOT_DIR}/uninstall.php" "${STAGING_DIR}/uninstall.php"
cp "${ROOT_DIR}/README.md" "${STAGING_DIR}/README.md"
cp "${ROOT_DIR}/CHANGELOG.md" "${STAGING_DIR}/CHANGELOG.md" 2>/dev/null || true
cp -R "${ROOT_DIR}/assets" "${STAGING_DIR}/assets"
cp -R "${ROOT_DIR}/languages" "${STAGING_DIR}/languages"
# Strip JSX sources from staged copy (we shipped only assets/dist)
rm -rf "${STAGING_DIR}/src/Admin/react-app"

# 7. ZIP
echo "→ packaging ZIP ${ZIP_PATH}"
(cd "${BUILD_DIR}" && zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}")

# 8. Checksum
CHECKSUM=$(shasum -a 256 "${ZIP_PATH}" | awk '{print $1}')
echo "${CHECKSUM}  $(basename "${ZIP_PATH}")" > "${ZIP_PATH}.sha256"

# 9. Done
SIZE=$(du -h "${ZIP_PATH}" | awk '{print $1}')
echo ""
echo "✓ Release built:"
echo "  File:     ${ZIP_PATH}"
echo "  Size:     ${SIZE}"
echo "  SHA-256:  ${CHECKSUM}"
```

- [ ] **Step 3 : Rendre le script exécutable**

```bash
chmod +x bin/build-release.sh
```

- [ ] **Step 4 : Dry-run (sera testé pour de vrai en Task 25)**

Pas d'exécution maintenant — le script dépend de `languages/` (Tasks 6-9) et `CHANGELOG.md` (Task 24) qui n'existent pas encore. On valide juste la syntaxe bash :

```bash
bash -n bin/build-release.sh
```

Attendu : pas de sortie (= syntaxe OK).

- [ ] **Step 5 : Commit**

```bash
git add bin/build-release.sh .distignore
git commit -m "build: bin/build-release.sh + .distignore (PHP-Scoper + ZIP pipeline)"
```

---

## Task 4 : i18n — audit des chaînes traduisibles

Avant de générer le POT, vérifier qu'aucune chaîne utilisateur (frontend ou e-mail) n'oublie le wrapper `__()`. Le POT extrait uniquement les chaînes wrappées.

**Files:**
- Modify: `src/**/*.php` (si chaînes nues détectées)

- [ ] **Step 1 : Grep des chaînes potentiellement nues dans les controllers**

```bash
grep -rn "new WP_Error\|return new WP_REST_Response" src/Http/ \
  | grep -v "__("
```

Les messages d'erreur des controllers REST doivent passer par `__()`. Lister chaque ligne qui ne contient pas `__(` ET qui retourne un message texte (e.g. `new WP_Error('code', 'message litérale', ...)`).

- [ ] **Step 2 : Wrapper toute chaîne d'erreur utilisateur trouvée**

Exemple typique à corriger (chercher des occurrences similaires) :

```php
// AVANT
return new WP_Error('tb_service_not_found', 'Service introuvable', ['status' => 404]);

// APRÈS
return new WP_Error('tb_service_not_found', __('Service introuvable', 'trinity-booking'), ['status' => 404]);
```

Faire ce pass sur :
- `src/Http/PublicBookingController.php`
- `src/Http/PublicCancelController.php`
- `src/Http/DecisionController.php`
- `src/Http/AdminBookingController.php`
- `src/Http/AdminGoogleController.php`
- `src/Http/AdminGoogleSettingsController.php`
- `src/Http/AdminSyncLogController.php`
- `src/Http/GoogleWebhookController.php`

Pour chaque, repérer les littérales utilisateur (messages d'erreur, labels, etc.) — laisser les codes d'erreur techniques (`tb_service_not_found`) en l'état.

- [ ] **Step 3 : Grep des chaînes JSX**

```bash
grep -rn "['\"][A-ZÉÀÈÊÎÔÛ][^'\"]\{8,\}" src/Admin/react-app/src/ \
  | grep -v "__("
```

Idem : toute chaîne user-facing doit être en `__('...', 'trinity-booking')`.

- [ ] **Step 4 : Lancer les tests pour valider qu'on n'a rien cassé**

```bash
composer test
composer stan
```

Attendu : tests verts (les valeurs `__()` retournent toujours la chaîne anglaise/française d'origine quand aucune traduction n'est chargée).

- [ ] **Step 5 : Commit (uniquement si des fichiers ont été modifiés)**

```bash
git add -u src/
git commit -m "i18n: wrap remaining user-facing error strings with __()"
```

Note : si l'audit ne trouve rien (tout est déjà wrappé) → pas de commit, passer à Task 5.

---

## Task 5 : i18n — générer `languages/trinity-booking.pot`

Le POT est le fichier modèle source pour toutes les traductions. Il liste les `msgid` (extraits du code) sans `msgstr`.

**Files:**
- Create: `languages/trinity-booking.pot`

- [ ] **Step 1 : Préreq — `wp-cli` disponible**

```bash
wp --version
```

Si absent :

```bash
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
sudo mv wp-cli.phar /usr/local/bin/wp
```

- [ ] **Step 2 : Créer le dossier `languages/`**

```bash
mkdir -p languages
```

- [ ] **Step 3 : Générer le POT**

```bash
wp i18n make-pot . languages/trinity-booking.pot \
  --slug=trinity-booking \
  --domain=trinity-booking \
  --include="src/**/*.php,trinity-booking.php" \
  --exclude="vendor,node_modules,build,tests,docs,assets/dist" \
  --headers='{"Project-Id-Version":"Trinity Booking 1.0.0","Last-Translator":"Trinity <nicolas@voilavoila.tv>","Language-Team":"French <nicolas@voilavoila.tv>","Report-Msgid-Bugs-To":"https://github.com/trinity/booking/issues"}' \
  --skip-audit
```

Attendu : fichier `languages/trinity-booking.pot` créé avec ~150-250 entrées `msgid "..."`.

- [ ] **Step 4 : Vérifier le contenu du POT**

```bash
head -30 languages/trinity-booking.pot
wc -l languages/trinity-booking.pot
grep -c "^msgid " languages/trinity-booking.pot
```

Attendu :
- Header avec `Project-Id-Version: Trinity Booking 1.0.0` et `"Content-Type: text/plain; charset=UTF-8\n"`.
- Plus de 150 entrées msgid (premiere = `""` qui est le header).

- [ ] **Step 5 : Aussi extraire les chaînes JSX**

`wp i18n make-pot` parse les chaînes JavaScript dans `assets/dist/` via les `.asset.php` files. Si le build npm n'a pas tourné récemment :

```bash
npm run build
wp i18n make-pot . languages/trinity-booking.pot \
  --slug=trinity-booking \
  --domain=trinity-booking \
  --include="src/**/*.php,trinity-booking.php,src/Admin/react-app/src/**/*.{js,jsx}" \
  --exclude="vendor,node_modules,build,tests,docs" \
  --headers='{"Project-Id-Version":"Trinity Booking 1.0.0","Last-Translator":"Trinity <nicolas@voilavoila.tv>","Language-Team":"French <nicolas@voilavoila.tv>","Report-Msgid-Bugs-To":"https://github.com/trinity/booking/issues"}' \
  --skip-audit
```

Vérifier ensuite que les chaînes JSX (ex. `'Réservations'`) apparaissent :

```bash
grep "Réservations" languages/trinity-booking.pot
```

Attendu : au moins une ligne `msgid "Réservations"`.

- [ ] **Step 6 : Commit**

```bash
git add languages/trinity-booking.pot
git commit -m "i18n: generate languages/trinity-booking.pot"
```

---

## Task 6 : i18n — fr_FR `.po` + `.mo` + `load_plugin_textdomain`

On crée le fichier `fr_FR.po` (souvent identité avec le POT puisque les chaînes sont déjà en français) puis on compile en `.mo` et on charge le text domain dans `Plugin::register()`.

**Files:**
- Create: `languages/trinity-booking-fr_FR.po`
- Create: `languages/trinity-booking-fr_FR.mo`
- Modify: `src/Plugin.php`

- [ ] **Step 1 : Copier le POT en PO et ajuster les headers**

```bash
cp languages/trinity-booking.pot languages/trinity-booking-fr_FR.po
```

Éditer ensuite le header du `fr_FR.po` :

```
"Project-Id-Version: Trinity Booking 1.0.0\n"
"Report-Msgid-Bugs-To: https://github.com/trinity/booking/issues\n"
"POT-Creation-Date: 2026-05-20T12:00:00+00:00\n"
"PO-Revision-Date: 2026-05-20T12:00:00+00:00\n"
"Last-Translator: Trinity <nicolas@voilavoila.tv>\n"
"Language-Team: French <nicolas@voilavoila.tv>\n"
"Language: fr_FR\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Plural-Forms: nplurals=2; plural=(n > 1);\n"
"X-Generator: WP-CLI 2.x\n"
"X-Domain: trinity-booking\n"
```

- [ ] **Step 2 : Remplir les `msgstr` identité-français**

Comme les chaînes source sont déjà en français, on peut faire un remplissage automatique avec un script Python ou un éditeur PO (Poedit). Version pragmatique en ligne de commande :

```bash
python3 - <<'PY'
import re
p = 'languages/trinity-booking-fr_FR.po'
with open(p) as f:
    s = f.read()
def repl(m):
    msgid = m.group(1)
    return f'msgid "{msgid}"\nmsgstr "{msgid}"'
# Remplir msgstr identité quand msgstr est vide et msgid non vide (sur une seule ligne).
out = re.sub(r'msgid "([^"]+)"\nmsgstr ""', repl, s)
with open(p, 'w') as f:
    f.write(out)
PY
```

Attendu : presque toutes les entrées ont maintenant `msgstr "..."` égal à `msgid "..."`. Pour les chaînes courtes en anglais (rares dans notre code, mais possibles), une étape manuelle est nécessaire — ouvrir le fichier dans Poedit pour les revoir si besoin.

- [ ] **Step 3 : Compiler en MO**

```bash
msgfmt languages/trinity-booking-fr_FR.po -o languages/trinity-booking-fr_FR.mo
```

Si `msgfmt` n'est pas installé : `brew install gettext` (macOS) ou `apt-get install gettext` (Linux).

Vérifier :

```bash
ls -la languages/trinity-booking-fr_FR.mo
```

Attendu : fichier binaire > 0 bytes.

- [ ] **Step 4 : Modifier `src/Plugin.php` — charger le textdomain au hook `init`**

Trouver la méthode `register()` (vers ligne 81). Ajouter en début :

```php
private function register(): void
{
    add_action('init', static function (): void {
        load_plugin_textdomain(
            'trinity-booking',
            false,
            'trinity-booking/languages'
        );
    }, 0);

    // ... reste du code existant inchangé
```

- [ ] **Step 5 : Test rapide en console PHP**

```bash
php -r "
require 'vendor/autoload.php';
// Simuler WP avec le minimum
require_once 'tests/bootstrap.php';
echo __('Réservations', 'trinity-booking');
"
```

Note : sans environnement WP réel, `__()` retourne le `msgid` brut. Le test réel se fait en activant le plugin sur un WP local — couvert par les tests manuels Task 27.

- [ ] **Step 6 : Lancer la suite complète**

```bash
composer test
composer stan
```

Attendu : tests verts.

- [ ] **Step 7 : Commit**

```bash
git add languages/ src/Plugin.php
git commit -m "i18n: add fr_FR translation + load_plugin_textdomain on init"
```

---

## Task 7 : RGPD — `Privacy\EmailMasker` helper

Helper pur (sans dépendance WP) qui masque un e-mail pour les logs. Utilisé en Task 12 par `BookingNotifier` / `PushEventJob` / `SyncEngine` si on trouve des fuites.

**Files:**
- Create: `src/Privacy/EmailMasker.php`
- Create: `tests/Unit/Privacy/EmailMaskerTest.php`

- [ ] **Step 1 : Écrire le test (TDD)**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Privacy\EmailMasker;

final class EmailMaskerTest extends TestCase
{
    public function test_masks_standard_email(): void
    {
        $this->assertSame('j***@e***', EmailMasker::mask('john.doe@example.com'));
    }

    public function test_masks_short_local_part(): void
    {
        $this->assertSame('a***@e***', EmailMasker::mask('a@example.com'));
    }

    public function test_masks_short_domain(): void
    {
        $this->assertSame('j***@d***', EmailMasker::mask('john@d.fr'));
    }

    public function test_returns_empty_string_for_invalid_email(): void
    {
        $this->assertSame('', EmailMasker::mask(''));
        $this->assertSame('', EmailMasker::mask('not-an-email'));
        $this->assertSame('', EmailMasker::mask('@example.com'));
        $this->assertSame('', EmailMasker::mask('john@'));
    }

    public function test_lowercases_before_masking(): void
    {
        $this->assertSame('j***@e***', EmailMasker::mask('JOHN@EXAMPLE.COM'));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter EmailMaskerTest
```

Attendu : `Class Trinity\Booking\Privacy\EmailMasker not found`.

- [ ] **Step 3 : Implémentation `src/Privacy/EmailMasker.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

final class EmailMasker
{
    /**
     * Returns a privacy-safe representation of an e-mail address for logs.
     * Format: "<first-char>***@<first-char>***"
     *
     * Returns empty string if the input is not a valid e-mail.
     */
    public static function mask(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return '';
        }
        return $local[0] . '***@' . $domain[0] . '***';
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter EmailMaskerTest
```

Attendu : 5 tests OK.

- [ ] **Step 5 : Commit**

```bash
git add src/Privacy/EmailMasker.php tests/Unit/Privacy/EmailMaskerTest.php
git commit -m "feat(privacy): EmailMasker helper for log PII protection"
```

---

## Task 8 : RGPD — audit + correctif fuite e-mails dans `sync_log`

On vérifie que `MailDispatcher` (Plan 2), `PushEventJob` (Plan 3) et `SyncEngine` (Plan 4) n'enregistrent jamais l'e-mail brut dans le payload du sync_log. Si trouvé → on masque via `EmailMasker`.

**Files:**
- Modify: `src/Notifications/BookingNotifier.php` (probablement)
- Modify: `src/Google/PushEventJob.php`
- Modify: `src/Google/SyncEngine.php`
- Modify: `src/Notifications/MailDispatcher.php` (si log direct dedans)

- [ ] **Step 1 : Audit grep**

```bash
grep -rn "customer_email\|customerEmail\|->email()" src/Notifications/ src/Google/ src/Persistence/SyncLogRepository.php
```

Lister chaque occurrence où un e-mail pourrait finir dans un payload JSON envoyé à `SyncLogRepository::append`.

- [ ] **Step 2 : Tracer les chemins de log**

```bash
grep -rn "syncLog->append\|SyncLog->append\|->append(" src/
```

Inspecter chaque appel `append(...)` : si le paramètre `$payload` (7e position) contient un e-mail brut (clé `to`, `customer_email`, etc.), c'est une fuite.

- [ ] **Step 3 : Correctifs ciblés**

Exemple typique attendu dans `BookingNotifier` ou `MailDispatcher` :

```php
// AVANT
$this->syncLog->append(
    'info', 'internal', 'booking', $bookingId, null,
    'mail_sent',
    'ok',
    ['to' => $booking->customerEmail(), 'event' => 'booking.confirmed.client'],
    null
);

// APRÈS
use Trinity\Booking\Privacy\EmailMasker;
// ...
$this->syncLog->append(
    'info', 'internal', 'booking', $bookingId, null,
    'mail_sent',
    'ok',
    ['to_masked' => EmailMasker::mask($booking->customerEmail()), 'event' => 'booking.confirmed.client'],
    null
);
```

Faire la même correction dans tout chemin de log identifié à Step 2.

- [ ] **Step 4 : Lancer la suite — vérifier qu'aucun test existant ne casse**

```bash
composer test
```

Si un test contrôlait la clé `to` dans le payload, l'adapter pour vérifier `to_masked` à la place.

- [ ] **Step 5 : Lancer PHPStan**

```bash
composer stan
```

Attendu : clean.

- [ ] **Step 6 : Commit**

```bash
git add -u src/ tests/
git commit -m "fix(privacy): mask customer e-mails before writing them to sync_log"
```

Note : si l'audit Step 1-2 ne trouve aucune fuite (e-mails déjà masqués partout) → pas de commit, passer à Task 9.

---

## Task 9 : RGPD — `Privacy\BookingExporter`

Export des données personnelles d'un client matchant une adresse e-mail. Branché sur le filter `wp_privacy_personal_data_exporters`.

**Files:**
- Create: `src/Privacy/BookingExporter.php`
- Create: `tests/Unit/Privacy/BookingExporterTest.php`
- Modify: `src/Persistence/BookingRepository.php` — ajouter `findByCustomerEmail`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Privacy;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Privacy\BookingExporter;

final class BookingExporterTest extends TestCase
{
    public function test_exports_data_for_matching_email(): void
    {
        $booking = $this->makeBooking('alice@example.com', 'Alice Martin');
        $exporter = new BookingExporter(
            findByEmail: fn (string $email) => $email === 'alice@example.com' ? [$booking] : [],
        );

        $result = $exporter->export('alice@example.com', 1);

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('trinity-booking', $result['data'][0]['group_id']);
        $this->assertSame((string) $booking->id(), $result['data'][0]['item_id']);
        $fields = array_column($result['data'][0]['data'], 'value', 'name');
        $this->assertSame('Alice Martin', $fields['Nom']);
        $this->assertSame('alice@example.com', $fields['E-mail']);
    }

    public function test_returns_empty_when_no_match(): void
    {
        $exporter = new BookingExporter(findByEmail: fn () => []);
        $result = $exporter->export('unknown@example.com', 1);

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    private function makeBooking(string $email, string $name): Booking
    {
        $tz = new DateTimeZone('Europe/Paris');
        $b = Booking::createPending(
            serviceId: 1,
            slot: new TimeSlot(
                new DateTimeImmutable('2026-06-01 10:00', $tz),
                new DateTimeImmutable('2026-06-01 11:30', $tz),
            ),
            timezone: 'Europe/Paris',
            customerName: $name,
            customerEmail: $email,
            customerPhone: '+33 6 12 34 56 78',
            customerAddress: '1 rue de la Paix, 75001 Paris',
            customerMeta: [],
            notes: 'Test',
        );
        $b->assignId(42);
        return $b;
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter BookingExporterTest
```

Attendu : `Class Trinity\Booking\Privacy\BookingExporter not found`.

- [ ] **Step 3 : Implémentation `src/Privacy/BookingExporter.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

use Closure;
use Trinity\Booking\Domain\Booking;

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
                'group_id'    => 'trinity-booking',
                'group_label' => __('Réservations Trinity Booking', 'trinity-booking'),
                'item_id'     => (string) ($b->id() ?? 0),
                'data'        => [
                    ['name' => __('Nom', 'trinity-booking'),     'value' => $b->customerName()],
                    ['name' => __('E-mail', 'trinity-booking'),  'value' => $b->customerEmail()],
                    ['name' => __('Téléphone', 'trinity-booking'),'value' => $b->customerPhone()],
                    ['name' => __('Adresse', 'trinity-booking'), 'value' => $b->customerAddress()],
                    ['name' => __('Notes', 'trinity-booking'),   'value' => $b->notes()],
                    ['name' => __('Statut', 'trinity-booking'),  'value' => $b->status()->value],
                    ['name' => __('Date du RDV', 'trinity-booking'), 'value' => $b->slot()->start->format('Y-m-d H:i')],
                    ['name' => __('Fuseau', 'trinity-booking'),  'value' => $b->timezone()],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }
}
```

Note : le `done: true` indique à WP qu'on a tout retourné en une page. Pour un client avec 10 000 bookings, on paginerait, mais nos volumes restent petits — V1 OK.

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter BookingExporterTest
```

Attendu : 2 tests OK.

- [ ] **Step 5 : Ajouter `BookingRepository::findByCustomerEmail`**

Modifier `src/Persistence/BookingRepository.php`, ajouter après `findByPublicUid` :

```php
/**
 * @return list<Booking>
 */
public function findByCustomerEmail(string $email): array
{
    $rows = $this->wpdb->get_results(
        $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE customer_email = %s ORDER BY starts_at_utc DESC",
            $email,
        ),
        ARRAY_A
    );
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_map(fn (array $r) => $this->fromRow($r), $rows));
}
```

- [ ] **Step 6 : Lancer PHPStan**

```bash
composer stan
```

Attendu : clean.

- [ ] **Step 7 : Commit**

```bash
git add src/Privacy/BookingExporter.php tests/Unit/Privacy/BookingExporterTest.php src/Persistence/BookingRepository.php
git commit -m "feat(privacy): BookingExporter for WP personal data exporters hook"
```

---

## Task 10 : RGPD — `Privacy\BookingEraser`

Anonymise les bookings d'un client (sans hard delete) pour rester conforme RGPD tout en préservant les agrégats.

**Files:**
- Create: `src/Privacy/BookingEraser.php`
- Create: `tests/Unit/Privacy/BookingEraserTest.php`
- Modify: `src/Persistence/BookingRepository.php` — ajouter `anonymizeByEmail`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Privacy\BookingEraser;

final class BookingEraserTest extends TestCase
{
    public function test_anonymizes_matching_bookings(): void
    {
        $calls = [];
        $eraser = new BookingEraser(
            anonymizeByEmail: function (string $email) use (&$calls): int {
                $calls[] = $email;
                return 3;
            },
        );

        $result = $eraser->erase('alice@example.com', 1);

        $this->assertSame(['alice@example.com'], $calls);
        $this->assertSame(3, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['messages']);
    }

    public function test_returns_zero_when_no_match(): void
    {
        $eraser = new BookingEraser(anonymizeByEmail: fn () => 0);
        $result = $eraser->erase('unknown@example.com', 1);

        $this->assertSame(0, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertSame([], $result['messages']);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter BookingEraserTest
```

- [ ] **Step 3 : Implémentation `src/Privacy/BookingEraser.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

use Closure;

final class BookingEraser
{
    /**
     * @param Closure(string): int $anonymizeByEmail returns count of anonymized rows.
     */
    public function __construct(private readonly Closure $anonymizeByEmail)
    {
    }

    /**
     * @return array{items_removed:int, items_retained:int, messages:list<string>, done:bool}
     */
    public function erase(string $email, int $page): array
    {
        $count = ($this->anonymizeByEmail)($email);

        $messages = $count > 0
            ? [
                sprintf(
                    /* translators: %d: number of bookings anonymized */
                    __('Trinity Booking : %d réservation(s) anonymisée(s) (les agrégats sont conservés).', 'trinity-booking'),
                    $count
                ),
            ]
            : [];

        return [
            'items_removed'  => $count,
            'items_retained' => 0,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter BookingEraserTest
```

- [ ] **Step 5 : Ajouter `BookingRepository::anonymizeByEmail`**

Dans `src/Persistence/BookingRepository.php`, après `findByCustomerEmail` :

```php
/**
 * Anonymizes all bookings matching the given e-mail, preserving aggregates.
 *
 * Replaces customer PII with a stable SHA-256 hash of the original e-mail
 * (idempotent — calling twice on the same e-mail is a no-op the second time
 * because the hash != original e-mail).
 *
 * @return int Number of rows updated.
 */
public function anonymizeByEmail(string $email): int
{
    $hash = hash('sha256', strtolower(trim($email)));
    $now  = current_time('mysql', true);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $updated = $this->wpdb->update(
        $this->table,
        [
            'customer_name'    => 'Anonyme',
            'customer_email'   => substr($hash, 0, 16) . '@anon.invalid',
            'customer_phone'   => '',
            'customer_address' => '',
            'customer_meta'    => wp_json_encode([]),
            'notes'            => '',
            'ip'               => null,
            'user_agent'       => null,
            'updated_at'       => $now,
        ],
        ['customer_email' => $email],
    );

    return is_int($updated) ? $updated : 0;
}
```

Note : on remplace `customer_email` par `<16chars hash>@anon.invalid` plutôt que par le hash brut, pour rester un format syntaxiquement valide d'e-mail (sinon les contrôleurs admin pourraient lever des erreurs de validation à l'affichage). Le `.invalid` TLD est réservé RFC 2606 — garantit pas d'envoi accidentel.

- [ ] **Step 6 : Lancer la suite + stan**

```bash
composer test
composer stan
```

- [ ] **Step 7 : Commit**

```bash
git add src/Privacy/BookingEraser.php tests/Unit/Privacy/BookingEraserTest.php src/Persistence/BookingRepository.php
git commit -m "feat(privacy): BookingEraser anonymizes booking PII via SHA-256 hash"
```

---

## Task 11 : RGPD — branchement WP des exporters/erasers + option mentions légales

On câble les hooks WP et on ajoute l'option `tb_legal_page_id` consommée par le shortcode.

**Files:**
- Modify: `src/Plugin.php` — register Privacy + filters
- Modify: `src/Http/AdminGoogleSettingsController.php` → renommer mentalement en "settings général" et ajouter `legal_page_id` (ou créer un nouveau controller)
- Modify: `src/PublicFront/Shortcode.php` — exposer `legal_url`
- Modify: `src/PublicFront/assets/booking.js` — afficher lien sous case consentement

Décision : on ajoute la persistance `legal_page_id` au controller `AdminGoogleSettingsController` (renommé conceptuellement en "AdminSettings"), mais sans renommer la classe pour minimiser le diff. On crée juste un nouvel endpoint `/admin/settings` ailleurs.

- [ ] **Step 1 : Créer `src/Http/AdminSettingsController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminSettingsController
{
    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'read'],
                'permission_callback' => $cap,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'write'],
                'permission_callback' => $cap,
            ],
        ]);
    }

    public function read(): WP_REST_Response
    {
        $legalId = (int) get_option('tb_legal_page_id', 0);
        $url     = $legalId > 0 ? (string) get_permalink($legalId) : '';
        return new WP_REST_Response([
            'legal_page_id'         => $legalId,
            'legal_url'             => $url,
            'booking_retention_days' => (int) get_option('tb_booking_retention_days', 1095),
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $legalId = (int) $req->get_param('legal_page_id');
        $retention = (int) $req->get_param('booking_retention_days');

        update_option('tb_legal_page_id', max(0, $legalId), false);
        if ($retention >= 30 && $retention <= 3650) {
            update_option('tb_booking_retention_days', $retention, false);
        }

        return new WP_REST_Response(['saved' => true], 200);
    }
}
```

- [ ] **Step 2 : Modifier `src/PublicFront/Shortcode.php` — exposer `legal_url` dans `wp_localize_script`**

Modifier la méthode `maybeEnqueue()`, dans le `wp_localize_script` :

```php
$legalId  = (int) get_option('tb_legal_page_id', 0);
$legalUrl = $legalId > 0 ? (string) get_permalink($legalId) : '';

wp_localize_script('trinity-booking-public', 'TrinityBooking', [
    'nonce'     => wp_create_nonce('wp_rest'),
    'locale'    => get_locale(),
    'legalUrl'  => $legalUrl,
]);
```

- [ ] **Step 3 : Modifier `src/PublicFront/assets/booking.js` — afficher lien**

Trouver l'endroit où la case de consentement est rendue (chercher `consent` dans le fichier) et ajouter sous le label :

```js
const legalUrl = (window.TrinityBooking && window.TrinityBooking.legalUrl) || '';
if (legalUrl) {
    const link = document.createElement('a');
    link.href = legalUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Mentions légales';
    link.className = 'tb-legal-link';
    // Inject after consent checkbox label
    consentLabel.appendChild(document.createTextNode(' — '));
    consentLabel.appendChild(link);
}
```

Note : la structure exacte du DOM dépend de l'implémentation Plan 1 du widget. Vérifier le code existant et adapter l'injection au bon endroit.

- [ ] **Step 4 : Modifier `src/Plugin.php` — wire AdminSettingsController + privacy hooks**

Dans `Plugin::register()`, ajouter (après le wiring de `Notifications\BookingNotifier`) :

```php
// AdminSettingsController est wiré dans RestRouter (Task 18). Ici on câble les Privacy hooks.

global $wpdb;
$bookings = $this->get(Persistence\BookingRepository::class) ?? new Persistence\BookingRepository($wpdb);

$exporter = new Privacy\BookingExporter(
    findByEmail: fn (string $email) => $bookings->findByCustomerEmail($email),
);
add_filter('wp_privacy_personal_data_exporters', static function (array $exporters) use ($exporter): array {
    $exporters['trinity-booking'] = [
        'exporter_friendly_name' => __('Trinity Booking', 'trinity-booking'),
        'callback'               => static fn (string $email, int $page = 1) => $exporter->export($email, $page),
    ];
    return $exporters;
});

$eraser = new Privacy\BookingEraser(
    anonymizeByEmail: fn (string $email) => $bookings->anonymizeByEmail($email),
);
add_filter('wp_privacy_personal_data_erasers', static function (array $erasers) use ($eraser): array {
    $erasers['trinity-booking'] = [
        'eraser_friendly_name' => __('Trinity Booking', 'trinity-booking'),
        'callback'             => static fn (string $email, int $page = 1) => $eraser->erase($email, $page),
    ];
    return $erasers;
});
```

Note : ajuster pour conserver l'accès au `BookingRepository` instance déjà câblée par `RestRouter` ; en cas de doute, instancier localement (les repositories sont sans état).

- [ ] **Step 5 : Lancer la suite**

```bash
composer test
composer stan
```

- [ ] **Step 6 : Commit**

```bash
git add src/Http/AdminSettingsController.php src/PublicFront/ src/Plugin.php
git commit -m "feat(privacy): wire WP exporters/erasers + tb_legal_page_id option"
```

---

## Task 12 : RGPD — cron mensuel `tb/purge_old_bookings`

Purge les bookings de plus de 3 ans (par défaut), statuts terminaux uniquement (`completed`, `cancelled`, `rejected`). Cron mensuel.

**Files:**
- Create: `src/Privacy/BookingRetentionPurger.php`
- Create: `tests/Unit/Privacy/BookingRetentionPurgerTest.php`
- Modify: `src/Persistence/BookingRepository.php` — ajouter `deleteOlderThan`
- Modify: `src/Activator.php` — scheduler tb_purge_old_bookings
- Modify: `src/Deactivator.php` — unscheduler
- Modify: `src/Plugin.php` — register handler

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Privacy\BookingRetentionPurger;

final class BookingRetentionPurgerTest extends TestCase
{
    public function test_deletes_with_correct_cutoff(): void
    {
        $captured = null;
        $purger = new BookingRetentionPurger(
            retentionDays: 1095,
            deleteOlderThan: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 7;
            },
            now: new DateTimeImmutable('2026-05-20 03:30:00'),
        );

        $count = $purger->purge();

        $this->assertSame(7, $count);
        $this->assertSame('2023-05-21 03:30:00', $captured->format('Y-m-d H:i:s'));
    }

    public function test_uses_custom_retention(): void
    {
        $captured = null;
        $purger = new BookingRetentionPurger(
            retentionDays: 365,
            deleteOlderThan: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 0;
            },
            now: new DateTimeImmutable('2026-05-20 00:00:00'),
        );

        $purger->purge();

        $this->assertSame('2025-05-20 00:00:00', $captured->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter BookingRetentionPurgerTest
```

- [ ] **Step 3 : Implémentation `src/Privacy/BookingRetentionPurger.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class BookingRetentionPurger
{
    public const HOOK = 'tb/purge_old_bookings';

    /**
     * @param Closure(DateTimeImmutable): int $deleteOlderThan returns count of deleted rows.
     */
    public function __construct(
        private readonly int $retentionDays,
        private readonly Closure $deleteOlderThan,
        private readonly DateTimeImmutable $now,
    ) {
    }

    public function purge(): int
    {
        $cutoff = $this->now->sub(new DateInterval('P' . $this->retentionDays . 'D'));
        return ($this->deleteOlderThan)($cutoff);
    }

    public static function fromOptions(): self
    {
        $days = (int) (function_exists('get_option') ? get_option('tb_booking_retention_days', 1095) : 1095);
        if ($days < 30) {
            $days = 30; // safety floor
        }
        global $wpdb;
        $repo = new \Trinity\Booking\Persistence\BookingRepository($wpdb);

        return new self(
            retentionDays: $days,
            deleteOlderThan: fn (DateTimeImmutable $cutoff) => $repo->deleteOlderThan($cutoff),
            now: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    public static function register(): void
    {
        add_action(self::HOOK, static function (): void {
            self::fromOptions()->purge();
        });
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter BookingRetentionPurgerTest
```

- [ ] **Step 5 : Ajouter `BookingRepository::deleteOlderThan`**

Dans `src/Persistence/BookingRepository.php`, après `anonymizeByEmail` :

```php
/**
 * Hard-deletes bookings whose ends_at_utc is older than $cutoff
 * and status is terminal (completed, cancelled, rejected).
 *
 * Confirmed future bookings are NEVER deleted.
 *
 * @return int Number of rows deleted.
 */
public function deleteOlderThan(\DateTimeImmutable $cutoff): int
{
    $cutoffStr = $cutoff->format('Y-m-d H:i:s');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $deleted = $this->wpdb->query(
        $this->wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE ends_at_utc < %s
             AND status IN ('completed', 'cancelled', 'rejected')",
            $cutoffStr,
        ),
    );
    return is_int($deleted) ? $deleted : 0;
}
```

- [ ] **Step 6 : Modifier `src/Activator.php` — schedule monthly cron**

Ajouter dans `activate()`, près des autres `wp_schedule_event` :

```php
// Custom monthly interval (WP doesn't ship one by default).
add_filter('cron_schedules', static function (array $s): array {
    if (!isset($s['tb_monthly'])) {
        $s['tb_monthly'] = [
            'interval' => 2_592_000, // 30 days in seconds
            'display'  => 'Once every 30 days (Trinity Booking)',
        ];
    }
    return $s;
});

if (!wp_next_scheduled(\Trinity\Booking\Privacy\BookingRetentionPurger::HOOK)) {
    wp_schedule_event(self::firstDayNextMonthAt0330SiteTz(), 'tb_monthly', \Trinity\Booking\Privacy\BookingRetentionPurger::HOOK);
}
```

Et ajouter la méthode helper en bas de la classe :

```php
private static function firstDayNextMonthAt0330SiteTz(): int
{
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    return (new \DateTimeImmutable('first day of next month 03:30', $tz))->getTimestamp();
}
```

- [ ] **Step 7 : Modifier `src/Deactivator.php`**

Ajouter à `deactivate()` :

```php
wp_clear_scheduled_hook(\Trinity\Booking\Privacy\BookingRetentionPurger::HOOK);
```

- [ ] **Step 8 : Modifier `src/Plugin.php` — register handler**

Dans `Plugin::register()`, après les Privacy hooks (Task 11) :

```php
// Cron interval must also be present at runtime, not just at activation.
add_filter('cron_schedules', static function (array $s): array {
    if (!isset($s['tb_monthly'])) {
        $s['tb_monthly'] = [
            'interval' => 2_592_000,
            'display'  => 'Once every 30 days (Trinity Booking)',
        ];
    }
    return $s;
});

Privacy\BookingRetentionPurger::register();
```

- [ ] **Step 9 : Lancer la suite + stan**

```bash
composer test
composer stan
```

- [ ] **Step 10 : Commit**

```bash
git add src/Privacy/BookingRetentionPurger.php tests/Unit/Privacy/BookingRetentionPurgerTest.php src/Persistence/BookingRepository.php src/Activator.php src/Deactivator.php src/Plugin.php
git commit -m "feat(privacy): monthly tb/purge_old_bookings cron with 3y retention default"
```

---

## Task 13 : Templates editor — `MailDispatcher::sendRaw` exposé

Avant le backend des templates, vérifier que `MailDispatcher` expose une méthode utilisable par le "Envoyer un test" : sujet + HTML + texte. Si elle n'existe pas ou est privée, l'extraire.

**Files:**
- Modify: `src/Notifications/MailDispatcher.php`

- [ ] **Step 1 : Lire le code existant**

```bash
grep -n "public function\|private function" src/Notifications/MailDispatcher.php
```

Si une méthode publique `sendRaw(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool` ou équivalent existe → passer à Task 14. Sinon → continuer.

- [ ] **Step 2 : Extraire `sendRaw` si absent**

Dans `src/Notifications/MailDispatcher.php`, ajouter (ou rendre publique) :

```php
/**
 * Sends a raw HTML e-mail without templating. Used for "send test"
 * from the templates editor.
 */
public function sendRaw(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
    ];

    $sent = wp_mail($to, $subject, $htmlBody, $headers);

    if ($textBody !== null) {
        // Optional plain-text alternative — would need PHPMailer alt body.
        // For V1 we send HTML-only test mails.
    }

    return (bool) $sent;
}
```

- [ ] **Step 3 : Lancer la suite**

```bash
composer test
composer stan
```

- [ ] **Step 4 : Commit (uniquement si modifié)**

```bash
git add src/Notifications/MailDispatcher.php
git commit -m "feat(notifications): MailDispatcher::sendRaw for template editor test send"
```

---

## Task 14 : Templates editor — `TagRegistryController` REST

Endpoint `GET /admin/tags` qui renvoie les tags groupés par catégorie pour alimenter le dropdown "Insérer un tag" du SPA.

**Files:**
- Create: `src/Http/TagRegistryController.php`
- Create: `tests/Integration/TagRegistryControllerTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use Trinity\Booking\Http\TagRegistryController;
use Trinity\Booking\Plugin;

/** @group rest */
final class TagRegistryControllerTest extends WP_UnitTestCase
{
    /** @var WP_REST_Server */
    private $server;

    public function setUp(): void
    {
        parent::setUp();
        global $wp_rest_server;
        $wp_rest_server = $this->server = new WP_REST_Server();
        do_action('rest_api_init');

        (new TagRegistryController())->registerRoutes();
    }

    public function test_returns_tags_grouped_by_category_for_admin(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/tags');
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertArrayHasKey('groups', $data);

        $cats = array_column($data['groups'], 'category');
        $this->assertContains('customer', $cats);
        $this->assertContains('appointment', $cats);
        $this->assertContains('actions', $cats);
        $this->assertContains('site', $cats);
    }

    public function test_forbidden_for_visitors(): void
    {
        wp_set_current_user(0);

        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/tags');
        $res = $this->server->dispatch($req);

        $this->assertSame(401, $res->get_status());
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test:integration -- --filter TagRegistryControllerTest
```

Attendu : `Class Trinity\Booking\Http\TagRegistryController not found` (ou suite ignorée si WP test suite absente — auquel cas tester via unit, voir Step 5).

- [ ] **Step 3 : Implémentation `src/Http/TagRegistryController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Notifications\TagRegistry;
use Trinity\Booking\Plugin;
use WP_REST_Response;

final class TagRegistryController
{
    public function __construct(private readonly TagRegistry $registry = new TagRegistry())
    {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/tags', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list'],
                'permission_callback' => $cap,
            ],
        ]);
    }

    public function list(): WP_REST_Response
    {
        $grouped = $this->registry->grouped();
        $groups  = [];
        foreach ($grouped as $category => $tags) {
            $groups[] = [
                'category' => $category,
                'label'    => $this->categoryLabel($category),
                'tags'     => $tags,
            ];
        }
        return new WP_REST_Response(['groups' => $groups], 200);
    }

    private function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'customer'    => __('Client', 'trinity-booking'),
            'appointment' => __('Rendez-vous', 'trinity-booking'),
            'actions'     => __('Liens d\'action', 'trinity-booking'),
            'site'        => __('Site', 'trinity-booking'),
            default       => $cat,
        };
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test:integration -- --filter TagRegistryControllerTest
```

- [ ] **Step 5 : Si la WP test suite n'est pas installée, créer un test unitaire de remplacement**

```php
// tests/Unit/Http/TagRegistryControllerTest.php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Http\TagRegistryController;
use Trinity\Booking\Notifications\TagRegistry;

final class TagRegistryControllerTest extends TestCase
{
    public function test_list_groups_tags_by_category(): void
    {
        $controller = new TagRegistryController(new TagRegistry());
        $response   = $controller->list();
        $data       = $response->get_data();

        $this->assertArrayHasKey('groups', $data);
        $cats = array_column($data['groups'], 'category');
        $this->assertContains('customer', $cats);
        $this->assertContains('appointment', $cats);
        $this->assertContains('actions', $cats);
        $this->assertContains('site', $cats);
    }
}
```

- [ ] **Step 6 : Commit**

```bash
git add src/Http/TagRegistryController.php tests/
git commit -m "feat(http): TagRegistryController GET /admin/tags grouped by category"
```

---

## Task 15 : Templates editor — `AdminMailTemplateController` REST

Endpoints :
- `GET /admin/mail-templates` — liste les 6 templates (event_key + subject + is_custom + updated_at)
- `GET /admin/mail-templates/{event_key}` — détail d'un template
- `POST /admin/mail-templates/{event_key}` — sauvegarde (custom)
- `DELETE /admin/mail-templates/{event_key}` — restaure le défaut
- `POST /admin/mail-templates/{event_key}/preview` — rend le template avec un dataset factice
- `POST /admin/mail-templates/{event_key}/test` — envoie un mail de test à l'utilisateur courant

**Files:**
- Create: `src/Http/AdminMailTemplateController.php`
- Create: `tests/Integration/AdminMailTemplateControllerTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use Trinity\Booking\Http\AdminMailTemplateController;
use Trinity\Booking\Notifications\DefaultTemplates;
use Trinity\Booking\Notifications\MailDispatcher;
use Trinity\Booking\Notifications\TagRegistry;
use Trinity\Booking\Notifications\TemplateRenderer;
use Trinity\Booking\Persistence\MailTemplateRepository;
use Trinity\Booking\Plugin;

/** @group rest */
final class AdminMailTemplateControllerTest extends WP_UnitTestCase
{
    /** @var WP_REST_Server */
    private $server;
    private MailTemplateRepository $repo;
    private int $admin;

    public function setUp(): void
    {
        parent::setUp();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = $this->server = new WP_REST_Server();
        do_action('rest_api_init');

        $this->repo = new MailTemplateRepository($wpdb);
        $registry   = new TagRegistry();
        $renderer   = new TemplateRenderer($registry);
        $dispatcher = $this->createMock(MailDispatcher::class);

        (new AdminMailTemplateController($this->repo, $renderer, $dispatcher))->registerRoutes();

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    public function test_list_returns_all_six_templates(): void
    {
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates');
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertCount(6, $data['templates']);
        foreach ($data['templates'] as $t) {
            $this->assertArrayHasKey('event_key', $t);
            $this->assertArrayHasKey('subject', $t);
            $this->assertArrayHasKey('is_custom', $t);
        }
    }

    public function test_get_single_returns_default_when_no_custom(): void
    {
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('booking.pending.client', $data['event_key']);
        $this->assertFalse($data['is_custom']);
        $this->assertNotEmpty($data['html_body']);
    }

    public function test_post_save_persists_custom_version(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $req->set_body_params([
            'subject'   => 'Custom subject {{customer_name}}',
            'html_body' => '<p>Hello {{customer_name}}</p>',
            'text_body' => null,
            'enabled'   => true,
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());

        // Re-fetch and check it's now custom.
        $req2 = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $res2 = $this->server->dispatch($req2);
        $data = $res2->get_data();
        $this->assertTrue($data['is_custom']);
        $this->assertSame('Custom subject {{customer_name}}', $data['subject']);
    }

    public function test_delete_restores_default(): void
    {
        // Seed a custom template.
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $req->set_body_params(['subject' => 'X', 'html_body' => 'Y', 'enabled' => true]);
        $this->server->dispatch($req);

        // Delete restores.
        $delReq = new WP_REST_Request('DELETE', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $delRes = $this->server->dispatch($delReq);
        $this->assertSame(200, $delRes->get_status());

        // Re-fetch — should be default.
        $getReq = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $getRes = $this->server->dispatch($getReq);
        $this->assertFalse($getRes->get_data()['is_custom']);
    }

    public function test_preview_renders_with_fake_context(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client/preview');
        $req->set_body_params([
            'subject'   => 'Test {{customer_name}}',
            'html_body' => '<p>Hello {{customer_name}} on {{appointment_date}}</p>',
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertStringContainsString('Test', $data['subject']);
        $this->assertStringContainsString('<p>Hello', $data['html']);
        // Fake context replaces tag with a sample value, not the raw {{tag}}.
        $this->assertStringNotContainsString('{{customer_name}}', $data['html']);
    }

    public function test_test_send_returns_ok(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client/test');
        $req->set_body_params([
            'subject'   => 'Hello {{customer_name}}',
            'html_body' => '<p>Hi {{customer_name}}</p>',
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['sent']);
    }

    public function test_forbidden_for_non_admin(): void
    {
        wp_set_current_user(0);
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates');
        $res = $this->server->dispatch($req);
        $this->assertSame(401, $res->get_status());
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test:integration -- --filter AdminMailTemplateControllerTest
```

- [ ] **Step 3 : Implémentation `src/Http/AdminMailTemplateController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Notifications\DefaultTemplates;
use Trinity\Booking\Notifications\Events\EventKey;
use Trinity\Booking\Notifications\MailDispatcher;
use Trinity\Booking\Notifications\TemplateRenderer;
use Trinity\Booking\Persistence\MailTemplateRepository;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminMailTemplateController
{
    public function __construct(
        private readonly MailTemplateRepository $repo,
        private readonly TemplateRenderer $renderer,
        private readonly MailDispatcher $dispatcher,
    ) {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);
        $ns  = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/mail-templates', [
            ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],     'permission_callback' => $cap],
            ['methods' => 'POST',   'callback' => [$this, 'save'],    'permission_callback' => $cap],
            ['methods' => 'DELETE', 'callback' => [$this, 'restore'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/preview', [
            ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/test', [
            ['methods' => 'POST', 'callback' => [$this, 'test'], 'permission_callback' => $cap],
        ]);
    }

    public function list(): WP_REST_Response
    {
        $items = [];
        foreach (EventKey::cases() as $key) {
            $t = $this->repo->getOrDefault($key);
            $items[] = [
                'event_key'  => $key->value,
                'subject'    => $t['subject'],
                'is_custom'  => $t['is_custom'],
                'enabled'    => $t['enabled'],
                'updated_at' => $t['updated_at'],
            ];
        }
        return new WP_REST_Response(['templates' => $items], 200);
    }

    public function get(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $t = $this->repo->getOrDefault($key);
        return new WP_REST_Response([
            'event_key'  => $key->value,
            'subject'    => $t['subject'],
            'html_body'  => $t['html_body'],
            'text_body'  => $t['text_body'],
            'enabled'    => $t['enabled'],
            'is_custom'  => $t['is_custom'],
            'updated_at' => $t['updated_at'],
            'updated_by' => $t['updated_by'],
        ], 200);
    }

    public function save(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }

        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');
        $text    = $req->get_param('text_body');
        $textVal = is_string($text) && $text !== '' ? $text : null;
        $enabled = (bool) $req->get_param('enabled');

        if (trim($subject) === '' || trim($html) === '') {
            return new WP_Error('tb_invalid_template', __('Sujet et corps HTML obligatoires.', 'trinity-booking'), ['status' => 400]);
        }

        $this->repo->save(
            $key,
            $subject,
            wp_kses_post($html),
            $textVal,
            $enabled,
            (int) get_current_user_id(),
        );

        return new WP_REST_Response(['saved' => true], 200);
    }

    public function restore(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $this->repo->delete($key);
        return new WP_REST_Response(['restored' => true], 200);
    }

    public function preview(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');
        $ctx     = $this->fakeContext($key);

        $rendered = [
            'subject' => $this->renderer->render($subject, $ctx),
            'html'    => wp_kses_post($this->renderer->render($html, $ctx)),
        ];
        return new WP_REST_Response($rendered, 200);
    }

    public function test(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');

        $ctx = $this->fakeContext($key);
        $renderedSubject = $this->renderer->render($subject, $ctx);
        $renderedHtml    = $this->renderer->render($html, $ctx);

        $user = wp_get_current_user();
        $to   = (string) ($user->user_email ?? get_option('admin_email'));
        $sent = $this->dispatcher->sendRaw($to, '[Test] ' . $renderedSubject, $renderedHtml, null);

        return new WP_REST_Response(['sent' => $sent, 'to' => $to], 200);
    }

    private function parseKey(WP_REST_Request $req): EventKey|WP_Error
    {
        $raw = (string) $req->get_param('event_key');
        $key = EventKey::tryFrom($raw);
        if ($key === null) {
            return new WP_Error('tb_unknown_event_key', __('Event key inconnue.', 'trinity-booking'), ['status' => 404]);
        }
        return $key;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function fakeContext(EventKey $key): array
    {
        $tomorrow = (new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Europe/Paris')));
        return [
            'customer_name'    => 'Jeanne Dupont',
            'customer_email'   => 'jeanne.dupont@example.com',
            'customer_phone'   => '+33 6 12 34 56 78',
            'customer_address' => '15 rue de la République, 75011 Paris',
            'service_name'     => 'Photovoltaïque',
            'service_duration' => '1h30',
            'appointment_date' => $tomorrow->format('Y-m-d'),
            'appointment_time' => $tomorrow->format('H:i'),
            'appointment_end'  => $tomorrow->modify('+90 minutes')->format('H:i'),
            'timezone'         => 'Europe/Paris',
            'notes'            => 'Maison de 110 m² orientée sud.',
            'cancel_url'       => 'https://example.com/cancel?uid=preview',
            'confirm_url'      => 'https://example.com/confirm?id=preview',
            'reject_url'       => 'https://example.com/reject?id=preview',
            'ics_url'          => 'https://example.com/preview.ics',
            'site_name'        => (string) get_option('blogname', 'Trinity'),
            'site_url'         => (string) get_option('home', 'https://example.com'),
            'admin_email'      => (string) get_option('admin_email', 'admin@example.com'),
            'company_logo'     => '',
            'company_phone'    => '+33 1 23 45 67 89',
        ];
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test:integration -- --filter AdminMailTemplateControllerTest
composer stan
```

- [ ] **Step 5 : Commit**

```bash
git add src/Http/AdminMailTemplateController.php tests/Integration/AdminMailTemplateControllerTest.php
git commit -m "feat(http): AdminMailTemplateController REST CRUD + preview + test"
```

---

## Task 16 : Wiring REST — `RestRouter` + `AdminSettingsController`

On câble les 3 nouveaux controllers (`AdminMailTemplateController`, `TagRegistryController`, `AdminSettingsController`) dans `RestRouter`.

**Files:**
- Modify: `src/Http/RestRouter.php`

- [ ] **Step 1 : Localiser la zone de wiring**

```bash
grep -n "registerRoutes\|new Admin\|new Public" src/Http/RestRouter.php
```

- [ ] **Step 2 : Ajouter les instanciations dans `registerRoutes()`**

À la fin de la méthode `registerRoutes()` (après les controllers existants), ajouter :

```php
// --- Plan 5 : templates editor + settings ---
$mailRepo   = new \Trinity\Booking\Persistence\MailTemplateRepository($wpdb);
$tagRegistry = new \Trinity\Booking\Notifications\TagRegistry();
$renderer   = new \Trinity\Booking\Notifications\TemplateRenderer($tagRegistry);

// MailDispatcher dépend de ces 3 — réutiliser l'instance déjà câblée Plan 2 si disponible
// (DI léger : on récupère depuis Plugin::instance()->get() si set, sinon on instancie minimal).
$dispatcher = $this->resolveMailDispatcher($renderer, $tagRegistry, $mailRepo);

(new \Trinity\Booking\Http\AdminMailTemplateController($mailRepo, $renderer, $dispatcher))->registerRoutes();
(new \Trinity\Booking\Http\TagRegistryController($tagRegistry))->registerRoutes();
(new \Trinity\Booking\Http\AdminSettingsController())->registerRoutes();
```

- [ ] **Step 3 : Ajouter la méthode helper `resolveMailDispatcher`**

À la fin de la classe `RestRouter` :

```php
private function resolveMailDispatcher(
    \Trinity\Booking\Notifications\TemplateRenderer $renderer,
    \Trinity\Booking\Notifications\TagRegistry $tags,
    \Trinity\Booking\Persistence\MailTemplateRepository $repo,
): \Trinity\Booking\Notifications\MailDispatcher {
    try {
        return \Trinity\Booking\Plugin::instance()->get(\Trinity\Booking\Notifications\MailDispatcher::class);
    } catch (\RuntimeException) {
        global $wpdb;
        $textGen = new \Trinity\Booking\Notifications\TextBodyGenerator();
        $ics     = new \Trinity\Booking\Notifications\IcsBuilder();
        $syncLog = new \Trinity\Booking\Persistence\SyncLogRepository($wpdb);
        return new \Trinity\Booking\Notifications\MailDispatcher(
            repo: $repo,
            renderer: $renderer,
            textGen: $textGen,
            ics: $ics,
            syncLog: $syncLog,
            tags: $tags,
        );
    }
}
```

Note : adapter les paramètres du ctor `MailDispatcher` aux signatures réelles existantes — vérifier avec :

```bash
grep -A 20 "class MailDispatcher" src/Notifications/MailDispatcher.php | head -25
```

et corriger les noms / ordre si nécessaire.

- [ ] **Step 4 : Lancer toute la suite**

```bash
composer test
composer test:integration
composer stan
```

Attendu : tous verts. Si stan flag un signature mismatch sur `MailDispatcher::__construct` → corriger les paramètres dans `resolveMailDispatcher`.

- [ ] **Step 5 : Commit**

```bash
git add src/Http/RestRouter.php
git commit -m "feat(http): wire AdminMailTemplateController + TagRegistry + AdminSettings in RestRouter"
```

---

## Task 17 : Templates editor frontend — install CodeMirror + extension api.js

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json` (auto)
- Modify: `src/Admin/react-app/src/api.js`

- [ ] **Step 1 : Installer les paquets npm**

```bash
npm install --save @uiw/react-codemirror@^4 @codemirror/lang-html@^6
```

Attendu : `package.json` met à jour `dependencies` :
```json
"@uiw/react-codemirror": "^4.x.x",
"@codemirror/lang-html": "^6.x.x"
```

- [ ] **Step 2 : Vérifier que le build SPA passe toujours**

```bash
npm run build
```

Attendu : pas d'erreur webpack. Le bundle `assets/dist/index.jsx.js` est sensiblement plus gros (~150 KB minifié au lieu de ~80 KB).

- [ ] **Step 3 : Modifier `src/Admin/react-app/src/api.js` — ajouter les méthodes templates et settings**

À la fin du fichier :

```js
// --- Plan 5 : templates editor ---

export async function listMailTemplates() {
	return apiFetch( { path: 'admin/mail-templates' } );
}

export async function fetchMailTemplate( eventKey ) {
	return apiFetch( { path: `admin/mail-templates/${ eventKey }` } );
}

export async function saveMailTemplate( eventKey, { subject, htmlBody, textBody, enabled } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }`,
		method: 'POST',
		data: {
			subject,
			html_body: htmlBody,
			text_body: textBody,
			enabled,
		},
	} );
}

export async function restoreMailTemplate( eventKey ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }`,
		method: 'DELETE',
	} );
}

export async function previewMailTemplate( eventKey, { subject, htmlBody } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }/preview`,
		method: 'POST',
		data: { subject, html_body: htmlBody },
	} );
}

export async function sendTestMailTemplate( eventKey, { subject, htmlBody } ) {
	return apiFetch( {
		path: `admin/mail-templates/${ eventKey }/test`,
		method: 'POST',
		data: { subject, html_body: htmlBody },
	} );
}

export async function listTags() {
	return apiFetch( { path: 'admin/tags' } );
}

// --- Plan 5 : settings (legal page, retention) ---

export async function fetchSettings() {
	return apiFetch( { path: 'admin/settings' } );
}

export async function saveSettings( { legalPageId, bookingRetentionDays } ) {
	return apiFetch( {
		path: 'admin/settings',
		method: 'POST',
		data: {
			legal_page_id: legalPageId,
			booking_retention_days: bookingRetentionDays,
		},
	} );
}
```

- [ ] **Step 4 : Commit**

```bash
git add package.json package-lock.json src/Admin/react-app/src/api.js
git commit -m "feat(spa): add CodeMirror deps + templates/settings api.js methods"
```

---

## Task 18 : `TemplatesPage.jsx` — liste des 6 templates

Composant qui liste les 6 templates avec leur statut (`is_custom` badge) et un click → ouvre `TemplateEditor`.

**Files:**
- Create: `src/Admin/react-app/src/TemplatesPage.jsx`

- [ ] **Step 1 : Créer le fichier**

```jsx
import { useEffect, useState } from '@wordpress/element';
import { Card, CardBody, CardHeader, Notice, Spinner, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listMailTemplates } from './api';
import TemplateEditor from './TemplateEditor';

const EVENT_LABELS = {
	'booking.pending.client'   : 'Demande reçue (client)',
	'booking.pending.admin'    : 'Nouvelle demande (admin)',
	'booking.confirmed.client' : 'RDV confirmé (client)',
	'booking.rejected.client'  : 'RDV refusé (client)',
	'booking.cancelled.client' : 'Annulation prise en compte (client)',
	'booking.reminder.client'  : 'Rappel J-1 (client)',
};

export default function TemplatesPage() {
	const [ items, setItems ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( null );

	const reload = async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await listMailTemplates();
			setItems( data.templates );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		reload();
	}, [] );

	if ( selected ) {
		return (
			<TemplateEditor
				eventKey={ selected }
				onClose={ () => {
					setSelected( null );
					reload();
				} }
			/>
		);
	}

	return (
		<div className="tb-templates-page">
			<Card>
				<CardHeader>
					<h2>{ __( 'Templates e-mail', 'trinity-booking' ) }</h2>
				</CardHeader>
				<CardBody>
					{ loading && <Spinner /> }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ items && (
						<table className="widefat striped tb-templates-table">
							<thead>
								<tr>
									<th>{ __( 'Évènement', 'trinity-booking' ) }</th>
									<th>{ __( 'Sujet', 'trinity-booking' ) }</th>
									<th>{ __( 'État', 'trinity-booking' ) }</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( t ) => (
									<tr key={ t.event_key }>
										<td>
											<strong>
												{ EVENT_LABELS[ t.event_key ] || t.event_key }
											</strong>
											<br />
											<code style={ { fontSize: '11px', color: '#666' } }>
												{ t.event_key }
											</code>
										</td>
										<td>{ t.subject }</td>
										<td>
											{ t.is_custom ? (
												<span className="tb-badge tb-badge-custom">
													{ __( 'Personnalisé', 'trinity-booking' ) }
												</span>
											) : (
												<span className="tb-badge tb-badge-default">
													{ __( 'Défaut', 'trinity-booking' ) }
												</span>
											) }
										</td>
										<td>
											<Button
												variant="secondary"
												onClick={ () => setSelected( t.event_key ) }
											>
												{ __( 'Modifier', 'trinity-booking' ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
```

- [ ] **Step 2 : Vérifier que le build passe**

```bash
npm run build
```

Note : le composant importe `TemplateEditor` qu'on créera Task 19. Le build va échouer ; c'est OK temporairement — on commitera après Task 19.

- [ ] **Step 3 : Pas de commit isolé — continuer Task 19**

---

## Task 19 : `TemplateEditor.jsx` — split-pane CodeMirror + preview + tags + test + restore

Le composant le plus dense du Plan 5. Split layout : éditeur (sujet + CodeMirror HTML + body texte) à gauche, preview (iframe) à droite. Barre d'actions : Enregistrer / Restaurer / Envoyer un test. Dropdown "Insérer un tag" au-dessus de l'éditeur HTML.

**Files:**
- Create: `src/Admin/react-app/src/TemplateEditor.jsx`
- Modify: `src/Admin/react-app/src/styles.scss` — styles split + badges

- [ ] **Step 1 : Créer le composant**

```jsx
import { useEffect, useState, useRef } from '@wordpress/element';
import {
	Card, CardBody, CardHeader,
	Button, TextControl, TextareaControl,
	Notice, Spinner, SelectControl, Flex, FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import CodeMirror from '@uiw/react-codemirror';
import { html as htmlLang } from '@codemirror/lang-html';
import {
	fetchMailTemplate, saveMailTemplate, restoreMailTemplate,
	previewMailTemplate, sendTestMailTemplate, listTags,
} from './api';

export default function TemplateEditor( { eventKey, onClose } ) {
	const [ template, setTemplate ] = useState( null );
	const [ subject, setSubject ] = useState( '' );
	const [ htmlBody, setHtmlBody ] = useState( '' );
	const [ textBody, setTextBody ] = useState( '' );
	const [ enabled, setEnabled ] = useState( true );
	const [ tagGroups, setTagGroups ] = useState( [] );
	const [ selectedTag, setSelectedTag ] = useState( '' );
	const [ preview, setPreview ] = useState( { subject: '', html: '' } );
	const [ isDirty, setIsDirty ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );
	const [ error, setError ] = useState( null );

	const codeMirrorRef = useRef( null );

	const load = async () => {
		setLoading( true );
		setError( null );
		try {
			const [ tpl, tags ] = await Promise.all( [
				fetchMailTemplate( eventKey ),
				listTags(),
			] );
			setTemplate( tpl );
			setSubject( tpl.subject );
			setHtmlBody( tpl.html_body );
			setTextBody( tpl.text_body ?? '' );
			setEnabled( !! tpl.enabled );
			setTagGroups( tags.groups );
			setIsDirty( false );
			await refreshPreview( tpl.subject, tpl.html_body );
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setLoading( false );
		}
	};

	const refreshPreview = async ( sub, body ) => {
		try {
			const p = await previewMailTemplate( eventKey, {
				subject: sub,
				htmlBody: body,
			} );
			setPreview( p );
		} catch ( e ) {
			// Silent on preview errors — show in main panel
		}
	};

	useEffect( () => {
		load();
	}, [ eventKey ] );

	// Debounce preview when content changes
	useEffect( () => {
		if ( ! template ) {
			return;
		}
		const id = setTimeout( () => {
			refreshPreview( subject, htmlBody );
		}, 400 );
		return () => clearTimeout( id );
	}, [ subject, htmlBody ] );

	const onSubjectChange = ( v ) => {
		setSubject( v );
		setIsDirty( true );
	};
	const onHtmlChange = ( v ) => {
		setHtmlBody( v );
		setIsDirty( true );
	};
	const onTextChange = ( v ) => {
		setTextBody( v );
		setIsDirty( true );
	};

	const insertTag = ( tagName ) => {
		if ( ! tagName ) return;
		const insertion = `{{${ tagName }}}`;
		setHtmlBody( ( current ) => current + insertion );
		setIsDirty( true );
		setSelectedTag( '' );
	};

	const save = async () => {
		setSaving( true );
		setMessage( null );
		setError( null );
		try {
			await saveMailTemplate( eventKey, {
				subject,
				htmlBody,
				textBody: textBody.trim() === '' ? null : textBody,
				enabled,
			} );
			setIsDirty( false );
			setMessage( __( 'Template enregistré.', 'trinity-booking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		} finally {
			setSaving( false );
		}
	};

	const restore = async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Restaurer le template par défaut ?', 'trinity-booking' ) ) ) {
			return;
		}
		try {
			await restoreMailTemplate( eventKey );
			setMessage( __( 'Template par défaut restauré.', 'trinity-booking' ) );
			await load();
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	const sendTest = async () => {
		try {
			const r = await sendTestMailTemplate( eventKey, { subject, htmlBody } );
			setMessage(
				r.sent
					? __( 'E-mail de test envoyé à : ', 'trinity-booking' ) + r.to
					: __( 'Échec de l\'envoi du test.', 'trinity-booking' )
			);
		} catch ( e ) {
			setError( e.message ?? String( e ) );
		}
	};

	const close = () => {
		if ( isDirty ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Modifications non sauvegardées, quitter ?', 'trinity-booking' ) ) ) {
				return;
			}
		}
		onClose();
	};

	if ( loading ) {
		return (
			<Card>
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	const tagOptions = [ { label: __( '— Insérer un tag —', 'trinity-booking' ), value: '' } ];
	tagGroups.forEach( ( g ) => {
		g.tags.forEach( ( t ) => {
			tagOptions.push( {
				label: `${ g.label } · {{${ t.name }}} — ${ t.description }`,
				value: t.name,
			} );
		} );
	} );

	return (
		<Card>
			<CardHeader>
				<Flex>
					<FlexItem>
						<h2 style={ { margin: 0 } }>
							{ __( 'Édition : ', 'trinity-booking' ) }
							<code>{ eventKey }</code>
							{ template?.is_custom && (
								<span className="tb-badge tb-badge-custom" style={ { marginLeft: 8 } }>
									{ __( 'Personnalisé', 'trinity-booking' ) }
								</span>
							) }
						</h2>
					</FlexItem>
					<FlexItem>
						<Button variant="tertiary" onClick={ close }>
							← { __( 'Retour à la liste', 'trinity-booking' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				{ message && (
					<Notice status="success" onRemove={ () => setMessage( null ) }>
						{ message }
					</Notice>
				) }
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }

				<div className="tb-template-split">
					<div className="tb-template-edit">
						<TextControl
							label={ __( 'Sujet de l\'e-mail', 'trinity-booking' ) }
							value={ subject }
							onChange={ onSubjectChange }
						/>

						<div className="tb-tag-picker">
							<SelectControl
								label={ __( 'Insérer un tag dans le corps HTML', 'trinity-booking' ) }
								options={ tagOptions }
								value={ selectedTag }
								onChange={ ( v ) => {
									setSelectedTag( v );
									insertTag( v );
								} }
							/>
						</div>

						<label className="tb-cm-label">
							{ __( 'Corps HTML', 'trinity-booking' ) }
						</label>
						<div className="tb-codemirror-wrap" ref={ codeMirrorRef }>
							<CodeMirror
								value={ htmlBody }
								height="380px"
								extensions={ [ htmlLang() ] }
								onChange={ onHtmlChange }
							/>
						</div>

						<TextareaControl
							label={ __( 'Version texte (laisser vide pour génération auto)', 'trinity-booking' ) }
							value={ textBody }
							onChange={ onTextChange }
							rows={ 5 }
						/>

						<Flex gap={ 2 } className="tb-template-actions">
							<FlexItem>
								<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ ! isDirty || saving }>
									{ __( 'Enregistrer', 'trinity-booking' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button variant="secondary" onClick={ sendTest }>
									{ __( 'Envoyer un test', 'trinity-booking' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button variant="tertiary" isDestructive onClick={ restore } disabled={ ! template?.is_custom }>
									{ __( 'Restaurer le défaut', 'trinity-booking' ) }
								</Button>
							</FlexItem>
						</Flex>
					</div>

					<div className="tb-template-preview">
						<h4>{ __( 'Aperçu live', 'trinity-booking' ) }</h4>
						<div className="tb-preview-subject">
							<strong>{ __( 'Sujet rendu : ', 'trinity-booking' ) }</strong>
							{ preview.subject }
						</div>
						<iframe
							className="tb-preview-iframe"
							title={ __( 'Aperçu du template', 'trinity-booking' ) }
							srcDoc={ preview.html }
							sandbox="allow-same-origin"
						/>
					</div>
				</div>
			</CardBody>
		</Card>
	);
}
```

- [ ] **Step 2 : Ajouter les styles dans `src/Admin/react-app/src/styles.scss`**

À la fin du fichier :

```scss
.tb-templates-table {
	td { vertical-align: middle; }
}

.tb-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;

	&-custom  { background: #fbbf24; color: #78350f; }
	&-default { background: #e5e7eb; color: #374151; }
}

.tb-template-split {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 24px;

	@media (max-width: 1100px) {
		grid-template-columns: 1fr;
	}
}

.tb-template-edit {
	.tb-codemirror-wrap {
		border: 1px solid #c3c4c7;
		border-radius: 2px;
		margin-bottom: 16px;
		overflow: hidden;
	}
	.tb-cm-label {
		display: block;
		font-size: 11px;
		font-weight: 500;
		margin: 12px 0 4px;
		text-transform: uppercase;
		color: #1e1e1e;
	}
	.tb-tag-picker { margin-bottom: 16px; }
	.tb-template-actions { margin-top: 16px; }
}

.tb-template-preview {
	border: 1px solid #c3c4c7;
	border-radius: 2px;
	padding: 16px;
	background: #f6f7f7;

	h4 { margin: 0 0 12px 0; }
	.tb-preview-subject {
		padding: 8px 12px;
		background: #fff;
		border: 1px solid #e2e4e7;
		margin-bottom: 12px;
		font-size: 13px;
	}
	.tb-preview-iframe {
		width: 100%;
		height: 480px;
		background: #fff;
		border: 1px solid #e2e4e7;
	}
}
```

- [ ] **Step 3 : Vérifier que le build passe**

```bash
npm run build
```

Attendu : pas d'erreur. Le bundle inclut maintenant CodeMirror.

- [ ] **Step 4 : Commit (Templates page + editor ensemble)**

```bash
git add src/Admin/react-app/src/TemplatesPage.jsx src/Admin/react-app/src/TemplateEditor.jsx src/Admin/react-app/src/styles.scss assets/dist/
git commit -m "feat(spa): TemplatesPage + TemplateEditor with CodeMirror + live preview"
```

---

## Task 20 : Ajouter l'onglet "Templates" dans `App.jsx`

**Files:**
- Modify: `src/Admin/react-app/src/App.jsx`

- [ ] **Step 1 : Modifier `App.jsx`**

Réécrire entièrement le fichier :

```jsx
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import GooglePage from './GooglePage';
import SyncLogPage from './SyncLogPage';
import TemplatesPage from './TemplatesPage';

export default function App() {
	const initial = window.location.hash.replace( '#/', '' ) || 'bookings';
	return (
		<div className="tb-admin">
			<TabPanel
				className="tb-tabs"
				tabs={ [
					{ name: 'bookings',  title: __( 'Réservations', 'trinity-booking' ) },
					{ name: 'google',    title: __( 'Google', 'trinity-booking' ) },
					{ name: 'templates', title: __( 'Templates', 'trinity-booking' ) },
					{ name: 'log',       title: __( 'Journal', 'trinity-booking' ) },
				] }
				initialTabName={ initial }
				onSelect={ ( name ) => {
					window.history.replaceState( null, '', `#/${ name }` );
				} }
			>
				{ ( tab ) => {
					if ( tab.name === 'google' ) return <GooglePage />;
					if ( tab.name === 'templates' ) return <TemplatesPage />;
					if ( tab.name === 'log' ) return <SyncLogPage />;
					return <BookingsPage />;
				} }
			</TabPanel>
		</div>
	);
}
```

- [ ] **Step 2 : Build**

```bash
npm run build
```

- [ ] **Step 3 : Test manuel rapide en browser (si WP local disponible)**

1. Activer le plugin dans WP local.
2. Ouvrir `wp-admin → Trinity Booking → onglet "Templates"`.
3. Vérifier que la liste des 6 templates s'affiche, tous avec badge "Défaut".
4. Cliquer "Modifier" sur `booking.pending.client`.
5. Modifier le HTML, observer l'aperçu live (~400ms debounce).
6. Cliquer "Insérer un tag" → choisir `{{customer_phone}}` → vérifier que ça s'insère dans CodeMirror.
7. Cliquer "Envoyer un test" → un mail arrive sur l'e-mail de l'admin.
8. Cliquer "Enregistrer" → succès, retour liste, badge "Personnalisé" affiché.
9. Cliquer "Modifier" → "Restaurer le défaut" → confirmer → badge "Défaut" de retour.

Si WP local non disponible : marquer ce step comme "à valider en Task 27 (test E2E manuel)" et continuer.

- [ ] **Step 4 : Commit**

```bash
git add src/Admin/react-app/src/App.jsx assets/dist/
git commit -m "feat(spa): add Templates tab in admin SPA"
```

---

## Task 21 : `CHANGELOG.md` — historique rétroactif Plans 1-5

Reconstitué depuis `git log --oneline`. Format Keep-a-Changelog (https://keepachangelog.com).

**Files:**
- Create: `CHANGELOG.md`

- [ ] **Step 1 : Extraire la liste des commits par plan**

```bash
git log --oneline --reverse | head -200
```

Identifier les césures Plan 1 → Plan 2 → … via les messages de commit ("docs: document Plan X" en général).

- [ ] **Step 2 : Créer `CHANGELOG.md`**

```markdown
# Changelog

Tous les changements notables de **trinity-booking** sont consignés ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) et le projet utilise [Semantic Versioning](https://semver.org/).

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
- Documentation `README.md` complète (walkthrough Google Cloud Console, screenshots SPA, troubleshooting).

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
```

- [ ] **Step 3 : Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: add CHANGELOG.md covering Plans 1-5 (v1.0.0)"
```

---

## Task 22 : `README.md` final — walkthrough complet + troubleshooting

Récriture de la partie quickstart pour refléter le périmètre V1 complet + section troubleshooting.

**Files:**
- Modify: `README.md`

- [ ] **Step 1 : Mettre à jour la section "Statut"**

Remplacer le bloc Statut par :

```markdown
## Statut

**Version courante : 1.0.0** — V1 stable, prête à déployer en production.

- ✅ **Plan 1** — fondations + parcours de réservation public minimal fonctionnel.
- ✅ **Plan 2** — notifications e-mail (6 events + templates + .ics) + validation admin (HMAC e-mail + dashboard React).
- ✅ **Plan 3** — Google OAuth + push WP → GCal via Action Scheduler.
- ✅ **Plan 4** — webhook + pull GCal → WP (SyncEngine + WatchChannelManager + crons).
- ✅ **Plan 5** — éditeur de templates CodeMirror + RGPD + i18n + packaging.

Voir `CHANGELOG.md` pour le détail.
```

- [ ] **Step 2 : Ajouter une section "Installation production"**

Après la section Pré-requis, insérer :

```markdown
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

7. (Optionnel) Définir la page "Mentions légales" : **Trinity Booking → … → Settings** (sera ajouté en V2 — pour V1, utiliser le filtre `update_option('tb_legal_page_id', <page_id>)`).
```

- [ ] **Step 3 : Ajouter une section "Troubleshooting" en fin de README**

```markdown
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
     -H "X-Goog-Channel-Token: $(wp option get tb_google_accounts | jq -r '.[0].watch_token_secret')"
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

### Comment configurer un commercial différent (V2) ?

V1 est mono-commercial. Pour la V2 multi-commercial : voir issue GitHub #X.

## Désinstallation

**Outils → Désinstaller des extensions** ne supprime pas les données par défaut. Pour un nettoyage complet :

```bash
wp db query "DROP TABLE wp_tb_services, wp_tb_bookings, wp_tb_busy_blocks, wp_tb_google_accounts, wp_tb_sync_log, wp_tb_mail_templates;"
wp option delete tb_db_version tb_decision_secret tb_google_client_id tb_google_client_secret tb_legal_page_id tb_booking_retention_days TRINITY_BOOKING_ENC_KEY_FALLBACK
```
```

- [ ] **Step 4 : Commit**

```bash
git add README.md
git commit -m "docs: README final — installation prod + troubleshooting + désinstallation"
```

---

## Task 23 : Construire le ZIP de release (dry-run)

Premier vrai lancement de `bin/build-release.sh`. Toutes les pré-requis sont en place : i18n, RGPD, templates, scoper config, languages/, CHANGELOG.

**Files:**
- Aucun fichier modifié (build artifact dans `build/`)

- [ ] **Step 1 : Lancer le script**

```bash
./bin/build-release.sh
```

Attendu : output proche de :
```
→ Building trinity-booking v1.0.0-rc1
→ composer install --no-dev (production deps)
→ npm run build (SPA assets)
→ php-scoper (prefix Trinity\Booking\Vendor)
→ composer dump-autoload (scoped, classmap-authoritative)
→ staging files into …/build/trinity-booking
→ packaging ZIP …/build/trinity-booking-1.0.0-rc1.zip
✓ Release built:
  File:     …/build/trinity-booking-1.0.0-rc1.zip
  Size:     ~6-9M
  SHA-256:  <hash>
```

- [ ] **Step 2 : Inspecter le contenu du ZIP**

```bash
unzip -l build/trinity-booking-1.0.0-rc1.zip | head -40
```

Vérifications :
- Présence : `trinity-booking/trinity-booking.php`, `trinity-booking/src/`, `trinity-booking/vendor/`, `trinity-booking/assets/dist/`, `trinity-booking/languages/`.
- Absence : `tests/`, `docs/`, `node_modules/`, `bin/`, `scoper.inc.php`.
- Inspecter `vendor/google/apiclient/src/Client.php` :

   ```bash
   unzip -p build/trinity-booking-1.0.0-rc1.zip trinity-booking/vendor/google/apiclient/src/Client.php | head -10
   ```

   Attendu : `namespace Trinity\Booking\Vendor\Google;` (scoper a fait son job).

- Inspecter `src/Google/PushEventJob.php` extrait :

   ```bash
   unzip -p build/trinity-booking-1.0.0-rc1.zip trinity-booking/src/Google/PushEventJob.php | grep "use"
   ```

   Attendu : `use Trinity\Booking\Vendor\Google\Client;` (au moins une ligne avec le prefix).

- [ ] **Step 3 : Test d'installation locale (si WP local disponible)**

```bash
# Détruire toute installation locale précédente
wp plugin deactivate trinity-booking 2>/dev/null
wp plugin delete trinity-booking 2>/dev/null

# Installer depuis le ZIP
wp plugin install build/trinity-booking-1.0.0-rc1.zip --activate
```

Vérifier :
- `wp plugin list | grep trinity-booking` → status `active`.
- `wp db query "SHOW TABLES LIKE 'wp_tb_%';"` → 6 tables.
- Visiter `/wp-admin/admin.php?page=trinity-booking` → SPA s'affiche avec les 4 onglets.

Si WP local non disponible : marquer ce step "à valider en post-merge tests manuels" et continuer.

- [ ] **Step 4 : Pas de commit (le ZIP est dans `build/` ignoré par git)**

S'assurer que `build/` est dans `.gitignore` :

```bash
grep "^build/$\|^build$" .gitignore || echo "build/" >> .gitignore
```

```bash
git status
```

Attendu : si `.gitignore` modifié → commit ; sinon working tree clean.

```bash
# Si .gitignore modifié
git add .gitignore
git commit -m "build: ignore release build/ directory"
```

---

## Task 24 : Bump final `1.0.0-rc1` → `1.0.0`

Dernière passe : la version stable.

**Files:**
- Modify: `trinity-booking.php`
- Modify: `src/Plugin.php`
- Modify: `package.json`
- Modify: `README.md` (déjà mis à jour Task 22, vérifier cohérence)

- [ ] **Step 1 : Bump version dans les 3 fichiers**

`trinity-booking.php` :

```php
 * Version:           1.0.0
```

`src/Plugin.php` :

```php
public const VERSION = '1.0.0';
```

`package.json` :

```json
"version": "1.0.0",
```

- [ ] **Step 2 : Vérifier que `README.md` mentionne déjà `1.0.0` (fait Task 22)**

```bash
grep "1.0.0\|1\\.0\\.0-rc1" README.md
```

Si une référence à `1.0.0-rc1` traîne → corriger en `1.0.0`.

- [ ] **Step 3 : Vérifier que `CHANGELOG.md` mentionne déjà `1.0.0` (fait Task 21)**

- [ ] **Step 4 : Re-construire le ZIP de release final**

```bash
./bin/build-release.sh
```

Attendu : `build/trinity-booking-1.0.0.zip` créé + checksum.

```bash
ls -lh build/trinity-booking-1.0.0.zip build/trinity-booking-1.0.0.zip.sha256
```

- [ ] **Step 5 : Lancer toute la suite finale**

```bash
composer test
composer test:integration  # si suite WP disponible, sinon skip
composer stan
composer cs                 # vérification PHPCS — peut warn, jamais bloquer
npm run build               # vérifier que le build SPA passe sans warning
```

Attendu :
- `composer test` : tests verts (~120+ tests avec Plan 5).
- `composer stan` : clean (level 8).
- `composer cs` : pas d'erreur **bloquante** (les warnings PSR-12 sur les vendors ne comptent pas — déjà exclus du `phpcs.xml.dist`).

- [ ] **Step 6 : Commit final**

```bash
git add trinity-booking.php src/Plugin.php package.json README.md
git commit -m "release: v1.0.0 — V1 stable (Plans 1-5 complete)"
```

- [ ] **Step 7 : Note pour Nicolas — tag git manuel**

Le tag `v1.0.0` n'est PAS créé automatiquement par ce plan. Quand tu valides la release :

```bash
git tag -a v1.0.0 -m "Trinity Booking 1.0.0 — V1 stable"
# (optionnel) push si remote configuré : git push origin v1.0.0
```

---

## Task 25 : Test E2E manuel — checklist post-deploy

Cette tâche n'est PAS une tâche d'écriture de code, c'est une checklist d'acceptation. À cocher au fur et à mesure sur un WP réel avec tunnel HTTPS et compte Google connecté.

**Files:**
- Aucun

- [ ] **Step 1 : Cycle complet client → admin**

1. Visiter la page contenant `[trinity_booking service="pv"]` en navigateur privé.
2. Choisir un créneau dispo → remplir formulaire (nom/email/tel/adresse/notes) → cocher consentement → submit.
3. Vérifier : page "demande envoyée" + e-mail "demande reçue" arrive côté client + e-mail admin "à valider" arrive avec boutons Confirmer / Refuser.
4. Cliquer "Confirmer" dans l'e-mail admin → page "✓ RDV confirmé".
5. Vérifier : client reçoit "RDV confirmé" avec `.ics` en pièce jointe.

- [ ] **Step 2 : Cycle annulation client**

1. Depuis l'e-mail client confirmé, cliquer "annuler ce RDV".
2. Vérifier : page "annulation prise en compte" + e-mail client "annulation confirmée" + event GCal supprimé.

- [ ] **Step 3 : Sync entrante GCal → WP**

1. Créer un event manuellement dans Google Calendar (sans passer par le plugin).
2. Attendre ≤ 30 secondes.
3. Vérifier dans **Trinity Booking → Journal** filtre `direction=g_to_wp` : ligne `entity=busy_block action=upsert`.
4. Tenter de réserver un créneau qui chevauche cet event : doit être bloqué.

- [ ] **Step 4 : Templates editor**

1. **Trinity Booking → Templates → booking.confirmed.client → Modifier**.
2. Modifier le sujet et le HTML.
3. "Insérer un tag" → `{{notes}}` → vérifier insertion.
4. Aperçu live se met à jour ~400ms après modif.
5. "Envoyer un test" → mail arrive sur compte admin.
6. "Enregistrer" → succès, badge "Personnalisé" affiché.
7. Re-tester un cycle confirm → vérifier que le mail utilise la version custom.
8. "Restaurer le défaut" → badge "Défaut" de retour.

- [ ] **Step 5 : Privacy exporter / eraser**

1. **WP Admin → Outils → Exporter les données personnelles** → saisir l'e-mail d'un booking de test → Envoyer la demande → Confirmer dans l'e-mail.
2. Vérifier : le ZIP exporté contient un groupe "Trinity Booking" avec les bookings.
3. **Outils → Effacer les données personnelles** → même e-mail → Envoyer la demande → Confirmer.
4. Vérifier : booking en base a `customer_name='Anonyme'`, `customer_email='xxxx@anon.invalid'`, `customer_phone=''`, etc.
5. Re-tenter eraser sur le même e-mail → 0 lignes touchées (idempotent).

- [ ] **Step 6 : Si tous les steps passent → marquer le Plan 5 comme COMPLET**

Mettre à jour `MEMORY.md` :

```
- [Vue d'ensemble trinity-booking](project_overview.md) — Calendly-like PV/IRVE + sync GCal ; 5 plans terminés, V1 stable.
```

(Cette mise à jour est faite par l'agent qui exécute le plan, pas par ce plan.)

---

## Self-Review (réalisée en interne par l'auteur du plan)

**1. Spec coverage — sections couvertes :**

- §2 Périmètre V1 / Templates éditeur CodeMirror + tags + preview + test + restore → Tasks 13-20.
- §2 i18n FR par défaut → Tasks 4-6.
- §2 RGPD : consentement (déjà Plan 1), hooks exporters/erasers, rétention logs 30j (déjà Plan 3), bookings retention configurable → Tasks 9-12.
- §8 Templates e-mail (8.1 events, 8.2 tags, 8.3 éditeur admin) → Tasks 13-20.
- §10 RGPD complet (mentions légales, export, effacement hash, sync_log masking, conservation 3 ans) → Tasks 7-12.
- §13 i18n (text domain, .pot, .po, .mo) → Tasks 4-6.
- §14 Installation & packaging (Composer dump-autoload optimisé, ZIP) → Tasks 2-3, 23-24.
- §15 Risque #1 (conflit namespaces vendors → PHP-Scoper) → Tasks 2-3.

**2. Placeholder scan :** aucun "TBD", "TODO", "fill in later". Toutes les code-cells contiennent du PHP/JSX/Bash réel exécutable.

**3. Type consistency :**
- `EventKey` enum référencé Tasks 14-15-18-19 → identique partout.
- `BookingExporter` / `BookingEraser` / `BookingRetentionPurger` signatures stables d'une tâche à l'autre.
- `MailDispatcher::sendRaw($to, $subject, $htmlBody, $textBody = null)` posé Task 13, consommé Task 15.
- `BookingRepository::findByCustomerEmail / anonymizeByEmail / deleteOlderThan` posées dans 3 tâches successives sans renaming.

**4. Gaps identifiés et acceptés :**
- Pas d'UI admin pour `tb_legal_page_id` / `tb_booking_retention_days` (les options s'éditent via `wp option set` en V1 — UI à ajouter en V2). Couvert par Task 11 backend REST mais sans page settings dans le SPA. Acceptable : volume très faible d'utilisation, le REST endpoint existe si on veut bricoler une UI plus tard.
- Pas de bloc Gutenberg distinct du shortcode (le bloc Gutenberg est en V2 selon spec §4). Le shortcode `[trinity_booking]` couvre la V1.
- Pas de tests Playwright E2E (spec §11 les mentionne, mais §17 étape 5 dit "polish + i18n + RGPD + tests E2E + packaging" — on traite E2E comme checklist manuelle Task 25, pas du code automatisé). V2 si l'équipe grandit.

---

## Récap exécution

| Bloc | Tasks | Estimation effort |
|------|-------|------------------|
| Version bump initial | Task 1 | 15 min |
| PHP-Scoper + build script | Tasks 2-3 | 2-3h |
| i18n (audit + POT + PO + textdomain) | Tasks 4-6 | 1-2h |
| RGPD (4 classes Privacy + helpers + hooks + cron) | Tasks 7-12 | 3-4h |
| Templates editor backend (controller + tests) | Tasks 13-15 | 2-3h |
| Templates editor frontend (CodeMirror + preview) | Tasks 16-20 | 3-4h |
| Documentation finale | Tasks 21-22 | 1h |
| Build + version finale | Tasks 23-24 | 1h |
| E2E manuel | Task 25 | 1h |
| **Total** | **25 tâches** | **~14-19h** |

Estimation alignée avec le scope d'un Plan 4 (~14 commits sur 1-2 jours pour un dev senior).

