# trinity-booking — Plan 3 : Google OAuth + push WP → GCal

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connecter un compte Google Calendar via OAuth 2.0 utilisateur, chiffrer les tokens, et synchroniser chaque transition de booking (création / confirmation / refus / annulation) vers Google Calendar en arrière-plan via Action Scheduler, avec un journal de synchronisation interrogeable depuis le dashboard.

**Architecture:**

- **`Google/`** — nouveau module. Trois responsabilités : (1) OAuth + chiffrement des tokens, (2) appels Calendar API derrière l'interface `CalendarGateway` (impl concrète + fake), (3) jobs Action Scheduler de push avec backoff exponentiel.
- **`Persistence/`** — deux nouveaux repositories (`GoogleAccountRepository`, `SyncLogRepository`). Schéma déjà migré Plan 1 — pas de DB version bump.
- **`Http/`** — deux nouveaux controllers (`AdminGoogleController` pour OAuth + statut + disconnect, `AdminSyncLogController` pour lecture du journal).
- **`Notifications/BookingNotifier`** reste inchangé : il continue d'envoyer les mails. **`Google\PushScheduler`** s'abonne aux mêmes hooks WP en parallèle et enfile un job Action Scheduler. Les deux pipelines (mail + GCal) sont indépendants.
- **Composer deps** : `google/apiclient` (Calendar API v3) + `woocommerce/action-scheduler`. PHP-Scoper repoussé au Plan 5 (packaging).

**Tech Stack:** PHP 8.1+, WordPress 6.5+, sodium (libsodium bundled), `google/apiclient` v2.x, Action Scheduler v3.x, PHPUnit 10 + Brain Monkey.

**Spec source:** `docs/superpowers/specs/2026-05-19-trinity-booking-design.md`, sections 2 (sync GCal), 4 (Google/), 5 (`wp_tb_google_accounts`, `wp_tb_sync_log`), 6.1 (jobs push), 7 (REST OAuth), 9 (chiffrement OAuth), 12 (observabilité), 15 (risques quotas / scoping).

---

## Préambule — Concepts clés pour l'ingénieur

Lire avant d'attaquer les tâches.

1. **OAuth 2.0 "Authorization Code" avec refresh token long-vivant.** Le flow standard : on génère une `auth_url` avec `access_type=offline` + `prompt=consent` (Google ne renvoie un refresh token qu'avec ces deux paramètres), l'admin autorise, Google redirige vers `/admin/google/oauth/callback?code=...&state=...`, on échange `code` contre `(access_token, refresh_token, expires_in)`. Le refresh token est **long-vivant** ; on le chiffre et on s'en sert pour rafraîchir l'access token (1 h de TTL). On ne re-demande l'autorisation **que** si le refresh token est révoqué ou si l'utilisateur déconnecte.

2. **Le `state` est notre garde CSRF.** Google ne signe rien : il renvoie le `state` qu'on lui a passé. On y met un HMAC SHA-256 de `"oauth|{user_id}|{exp}"` signé avec `tb_decision_secret` (déjà en place Plan 1). Expiration **10 minutes**. Vérifié dans le callback avant tout. Sans état valide → 403, on ne touche ni à `code` ni à Google.

3. **Chiffrement des refresh tokens.** `sodium_crypto_secretbox` (AEAD, authentifié) avec clé 32 octets résolue dans cet ordre : (1) constante PHP `TRINITY_BOOKING_ENC_KEY` (64 hex chars) définie dans `wp-config.php`, (2) option WP `tb_enc_key` générée automatiquement à la première utilisation. Si on tombe en (2), on affiche un `admin_notice` de niveau warning : "Définissez `TRINITY_BOOKING_ENC_KEY` dans wp-config.php pour une sécurité optimale." Format stocké : `base64(nonce . ciphertext)` (nonce 24 octets en préfixe).

4. **`CalendarGateway` = interface étroite, 3 méthodes.** `insertEvent(calendarId, payload): array{id,etag}` / `patchEvent(calendarId, eventId, payload): array{id,etag}` / `deleteEvent(calendarId, eventId): void`. Impl réelle = `GoogleApiCalendarGateway` (utilise `Google\Service\Calendar`). Impl test = `FakeCalendarGateway` (in-memory `array<string, array>`). On ne testera **jamais** le SDK Google : on teste notre code via la fake. Le SDK réel est couvert par `wp trinity-booking doctor`.

5. **Action Scheduler.** Plugin librairie chargé via `require_once vendor/woocommerce/action-scheduler/action-scheduler.php`. Fournit `as_enqueue_async_action($hook, $args)` (file FIFO) et gère automatiquement le retry (3 tentatives par défaut). On enregistre nos callbacks via `add_action('tb/push_gcal_event', ...)`. Si la lib n'est pas chargée (test unitaire pur), `PushScheduler` doit fonctionner en mode no-op silencieux (détection `function_exists('as_enqueue_async_action')`).

6. **Idempotence du push.** Le job `tb/push_gcal_event` reçoit `[bookingId, action]` où `action ∈ {create, confirm, delete}`. Si le booking n'existe plus → no-op. Si l'action est `create` et `google_event_id` est déjà set → no-op (on a déjà créé l'event lors d'une tentative précédente). Si `confirm` et l'event n'existe pas (jamais créé) → fallback create. Si `delete` et pas d'event → no-op. Toutes ces branches sont loggées.

7. **Color codes GCal.** `colorId` est un STRING. `6` = orange (`pending` → `[À VALIDER]` préfixé dans le summary). `10` = vert (`confirmed`, préfixe retiré). Options WP `tb_gcal_color_pending` et `tb_gcal_color_confirmed` permettent l'override (défauts `6` et `10`).

8. **Mapping Booking → Google Event.** Le `EventFormatter::format(Booking, Service)` produit le payload :
   ```
   summary    : "[À VALIDER] Photovoltaïque · Jean Dupont"   (préfixe seulement si pending)
   description: rendu HTML simple (nom, e-mail, téléphone, adresse, notes, lien admin)
   start.dateTime / end.dateTime : ISO8601 avec timezone IANA du booking
   colorId    : "6" ou "10"
   attendees  : [] (V1 ne notifie pas le client via Google ; on a déjà nos mails)
   ```

9. **Backoff Action Scheduler.** En cas d'`GoogleApiError` (status 5xx ou réseau), on lève l'exception → Action Scheduler retry avec délai croissant. Pour les `4xx` métier (400 bad request, 404 event not found, 410 gone), on **n'échoue pas** : on logge en `failed` et on `return` proprement (pas de retry sur erreur déterministe).

10. **Journal `wp_tb_sync_log`.** Une ligne par appel Google. Champs : `direction='wp_to_g'`, `entity='booking'`, `entity_id=$bookingId`, `action ∈ {create, update, delete, refresh_token, oauth_connect, oauth_disconnect}`, `status ∈ {ok, retry, failed}`, `payload` = JSON tronqué à 4 ko, `error_message` si échec. Purge `> 30 jours` par cron quotidien `tb_purge_sync_log`.

11. **REST OAuth — chemins publics vs protégés.**
    - `POST /admin/google/oauth/start` — capability `trinity_booking_manage` + nonce. Retourne `{ auth_url }`.
    - `GET /admin/google/oauth/callback` — **public** (Google redirige le browser ici, pas de cookie WP forcément valide selon configuration). Sécurité = HMAC sur `state`.
    - `GET /admin/google/status` — capability.
    - `POST /admin/google/disconnect` — capability + nonce.

12. **PHP-Scoper.** Ne **pas** scoper dans ce plan — repoussé au Plan 5 (packaging). On vit avec le risque de conflit `Google\` en V1. Si Nicolas a un autre plugin qui charge déjà `google/apiclient`, il devra le désactiver pour développer (documenté README).

---

## File Structure (Plan 3 scope)

```
plugins-booking/
├── composer.json                                # MODIFY — add google/apiclient + action-scheduler
├── composer.lock                                # AUTO — composer update
├── README.md                                    # MODIFY — document Google OAuth + TRINITY_BOOKING_ENC_KEY
├── trinity-booking.php                          # MODIFY — bootstrap Action Scheduler
├── src/
│   ├── Plugin.php                               # MODIFY — wire Google services
│   ├── Activator.php                            # MODIFY — schedule sync-log purge cron
│   ├── Deactivator.php                          # MODIFY — unschedule cron
│   ├── Domain/
│   │   └── GoogleAccount.php                    # NEW value object
│   ├── Google/
│   │   ├── Encryption.php                       # NEW — sodium wrapper
│   │   ├── EncryptionKeyResolver.php            # NEW — wp-config | option fallback
│   │   ├── OAuthState.php                       # NEW — HMAC state signer
│   │   ├── OAuthClient.php                      # NEW — exchange code + refresh
│   │   ├── CalendarGateway.php                  # NEW — interface
│   │   ├── GoogleApiCalendarGateway.php         # NEW — google/apiclient impl
│   │   ├── EventFormatter.php                   # NEW — Booking → event payload
│   │   ├── PushScheduler.php                    # NEW — hook → enqueue async
│   │   ├── PushEventJob.php                     # NEW — Action Scheduler handler
│   │   ├── SyncLogPurger.php                    # NEW — daily cron
│   │   └── Exceptions/
│   │       ├── OAuthFailure.php                 # NEW
│   │       ├── GoogleApiError.php               # NEW (transient/5xx)
│   │       ├── GoogleClientError.php            # NEW (deterministic 4xx)
│   │       └── EncryptionFailure.php            # NEW
│   ├── Persistence/
│   │   ├── GoogleAccountRepository.php          # NEW
│   │   └── SyncLogRepository.php                # NEW
│   ├── Http/
│   │   ├── AdminGoogleController.php            # NEW — REST
│   │   ├── AdminSyncLogController.php           # NEW — REST
│   │   └── RestRouter.php                       # MODIFY — register new controllers
│   ├── Admin/
│   │   ├── AdminMenu.php                        # MODIFY — submenu "Google" + "Journal"
│   │   └── react-app/src/
│   │       ├── App.jsx                          # MODIFY — tab nav
│   │       ├── api.js                           # MODIFY — google + sync-log endpoints
│   │       ├── GooglePage.jsx                   # NEW
│   │       └── SyncLogPage.jsx                  # NEW
│   └── Cli/
│       └── DoctorCommand.php                    # NEW — wp trinity-booking doctor
├── tests/
│   ├── Unit/
│   │   ├── Google/
│   │   │   ├── EncryptionTest.php               # NEW
│   │   │   ├── OAuthStateTest.php               # NEW
│   │   │   ├── EventFormatterTest.php           # NEW
│   │   │   ├── PushSchedulerTest.php            # NEW
│   │   │   ├── PushEventJobTest.php             # NEW (uses FakeCalendarGateway)
│   │   │   └── SyncLogPurgerTest.php            # NEW
│   │   └── Support/
│   │       └── FakeCalendarGateway.php          # NEW — in-memory test double
│   └── Integration/
│       ├── GoogleAccountRepositoryTest.php      # NEW
│       ├── SyncLogRepositoryTest.php            # NEW
│       ├── AdminGoogleControllerTest.php        # NEW (mock pre_http_request)
│       └── AdminSyncLogControllerTest.php       # NEW
```

---

## Workflow par tâche

1. Lire entièrement la tâche.
2. Écrire le(s) test(s) en premier.
3. Lancer → rouge avec le bon message.
4. Implémenter minimal.
5. Lancer → vert.
6. Lancer la suite complète (`composer test`) pour ne rien casser.
7. PHPStan + PHPCS si la tâche touche `src/`.
8. Commit conventional.

---

## Task 1 : Composer — ajouter google/apiclient + action-scheduler

**Files:**
- Modify: `composer.json`
- Auto: `composer.lock`, `vendor/`

- [ ] **Step 1 : Modifier `composer.json`**

Ajouter dans la section `"require"` :

```json
"require": {
    "php": ">=8.1",
    "ext-sodium": "*",
    "google/apiclient": "^2.15",
    "woocommerce/action-scheduler": "^3.7"
},
```

Et dans `"config"`, autoriser le hook auto-loader d'Action Scheduler (sinon Composer râle) :

```json
"config": {
    "allow-plugins": {
        "dealerdirect/phpcodesniffer-composer-installer": true,
        "composer/installers": true
    },
    "sort-packages": true
}
```

- [ ] **Step 2 : Mettre à jour**

```bash
composer update
```

Attendu : ~30 paquets ajoutés (Google client + Guzzle + Action Scheduler). `composer.lock` modifié.

- [ ] **Step 3 : Vérifier l'autoload**

```bash
composer dump-autoload -o
php -r "require 'vendor/autoload.php'; var_dump(class_exists(\Google\Client::class));"
```
Attendu : `bool(true)`.

- [ ] **Step 4 : Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add google/apiclient + action-scheduler dependencies"
```

---

## Task 2 : Bootstrap Action Scheduler depuis le plugin

**Files:**
- Modify: `trinity-booking.php`

Action Scheduler est une "library plugin" ; on charge son entry-point avant tout pour que `as_enqueue_async_action()` soit disponible.

- [ ] **Step 1 : Lire `trinity-booking.php` et localiser le `require` de l'autoloader composer**

- [ ] **Step 2 : Ajouter le bootstrap d'Action Scheduler juste après l'autoload composer**

Insérer après la ligne `require __DIR__ . '/vendor/autoload.php';` :

```php
// Action Scheduler bootstrap — must run before plugins_loaded so other plugins can enqueue.
$tb_as = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (is_readable($tb_as)) {
    require_once $tb_as;
}
unset($tb_as);
```

- [ ] **Step 3 : Vérifier**

```bash
php -r "define('ABSPATH', '/tmp/'); require 'vendor/autoload.php'; require 'vendor/woocommerce/action-scheduler/action-scheduler.php'; echo function_exists('as_enqueue_async_action') ? 'OK' : 'KO';"
```
Attendu : `OK` (la lib définit la fonction même hors WP réel).

- [ ] **Step 4 : Commit**

```bash
git add trinity-booking.php
git commit -m "build: bootstrap action-scheduler from plugin entry"
```

---

## Task 3 : `Google\Encryption` — sodium_crypto_secretbox wrapper

**Files:**
- Create: `src/Google/Encryption.php`
- Create: `src/Google/Exceptions/EncryptionFailure.php`
- Create: `tests/Unit/Google/EncryptionTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Google/EncryptionTest.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Google\Encryption;
use Trinity\Booking\Google\Exceptions\EncryptionFailure;

final class EncryptionTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        // 32-byte key
        $this->key = random_bytes(32);
    }

    public function test_round_trip(): void
    {
        $enc = new Encryption($this->key);
        $cipher = $enc->encrypt('hello world');
        self::assertNotSame('hello world', $cipher);
        self::assertSame('hello world', $enc->decrypt($cipher));
    }

    public function test_different_ciphertext_each_time(): void
    {
        $enc = new Encryption($this->key);
        self::assertNotSame($enc->encrypt('x'), $enc->encrypt('x'));
    }

    public function test_decrypt_with_wrong_key_throws(): void
    {
        $enc1 = new Encryption($this->key);
        $cipher = $enc1->encrypt('secret');
        $enc2 = new Encryption(random_bytes(32));
        $this->expectException(EncryptionFailure::class);
        $enc2->decrypt($cipher);
    }

    public function test_decrypt_tampered_throws(): void
    {
        $enc = new Encryption($this->key);
        $cipher = $enc->encrypt('secret');
        $tampered = base64_encode('garbage' . base64_decode($cipher, true));
        $this->expectException(EncryptionFailure::class);
        $enc->decrypt($tampered);
    }

    public function test_short_key_throws(): void
    {
        $this->expectException(EncryptionFailure::class);
        new Encryption('too-short');
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter EncryptionTest
```
Attendu : `Class Trinity\Booking\Google\Encryption not found`.

- [ ] **Step 3 : Créer `src/Google/Exceptions/EncryptionFailure.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google\Exceptions;

final class EncryptionFailure extends \RuntimeException
{
}
```

- [ ] **Step 4 : Créer `src/Google/Encryption.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Trinity\Booking\Google\Exceptions\EncryptionFailure;

final class Encryption
{
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const KEY_BYTES   = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    public function __construct(private readonly string $key)
    {
        if (\strlen($this->key) !== self::KEY_BYTES) {
            throw new EncryptionFailure(sprintf(
                'Encryption key must be %d bytes, got %d.',
                self::KEY_BYTES,
                \strlen($this->key),
            ));
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $bin = base64_decode($payload, true);
        if ($bin === false || \strlen($bin) <= self::NONCE_BYTES) {
            throw new EncryptionFailure('Invalid ciphertext payload.');
        }
        $nonce = substr($bin, 0, self::NONCE_BYTES);
        $cipher = substr($bin, self::NONCE_BYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new EncryptionFailure('Decryption failed (wrong key or tampered ciphertext).');
        }
        return $plain;
    }
}
```

- [ ] **Step 5 : Lancer → vert**

```bash
composer test -- --filter EncryptionTest
```
Attendu : 5 tests verts.

- [ ] **Step 6 : Commit**

```bash
git add src/Google/Encryption.php src/Google/Exceptions/EncryptionFailure.php tests/Unit/Google/EncryptionTest.php
git commit -m "feat(google): sodium secretbox encryption wrapper"
```

---

## Task 4 : `Google\EncryptionKeyResolver` — constante puis option

**Files:**
- Create: `src/Google/EncryptionKeyResolver.php`

Pas de test unitaire dédié : la résolution dépend de `defined()` + `get_option()` qui sont mockés via Brain Monkey. On le valide indirectement via le test d'intégration de `AdminGoogleController` plus tard. Implémentation pure et courte.

- [ ] **Step 1 : Créer le fichier**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Trinity\Booking\Google\Exceptions\EncryptionFailure;

final class EncryptionKeyResolver
{
    public const CONSTANT = 'TRINITY_BOOKING_ENC_KEY';
    public const OPTION   = 'tb_enc_key';

    public function resolve(): string
    {
        if (defined(self::CONSTANT)) {
            $hex = (string) constant(self::CONSTANT);
            $bin = $this->hexToBin($hex);
            if ($bin !== null) {
                return $bin;
            }
        }

        $stored = get_option(self::OPTION);
        if (is_string($stored) && $stored !== '') {
            $bin = $this->hexToBin($stored);
            if ($bin !== null) {
                return $bin;
            }
        }

        $bin = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        update_option(self::OPTION, bin2hex($bin), false);
        return $bin;
    }

    public function usingFallback(): bool
    {
        return !defined(self::CONSTANT);
    }

    private function hexToBin(string $hex): ?string
    {
        $hex = trim($hex);
        if (strlen($hex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 || !ctype_xdigit($hex)) {
            return null;
        }
        $bin = hex2bin($hex);
        return $bin === false ? null : $bin;
    }
}
```

- [ ] **Step 2 : Commit**

```bash
git add src/Google/EncryptionKeyResolver.php
git commit -m "feat(google): resolve encryption key from wp-config or option"
```

---

## Task 5 : `Domain\GoogleAccount` — value object

**Files:**
- Create: `src/Domain/GoogleAccount.php`
- Create: `tests/Unit/Domain/GoogleAccountTest.php`

V1 = mono-compte, mais on garde un objet domain pour préparer V2 sans casser.

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\GoogleAccount;

final class GoogleAccountTest extends TestCase
{
    public function test_connect_sets_tokens_and_expiry(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect(
            label: 'Commercial Trinity',
            calendarId: 'primary',
            refreshTokenEnc: 'enc-refresh',
            accessTokenEnc: 'enc-access',
            expiresAt: $now->modify('+3600 seconds'),
        );

        self::assertSame('primary', $acct->calendarId());
        self::assertSame('enc-refresh', $acct->refreshTokenEnc());
        self::assertSame('enc-access', $acct->accessTokenEnc());
        self::assertFalse($acct->accessTokenExpired($now));
    }

    public function test_access_token_expired_after_expiry(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now->modify('-1 second'));
        self::assertTrue($acct->accessTokenExpired($now));
    }

    public function test_rotate_access_token(): void
    {
        $now = new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now);
        $acct->rotateAccessToken('enc-new', $now->modify('+3600 seconds'));
        self::assertSame('enc-new', $acct->accessTokenEnc());
        self::assertFalse($acct->accessTokenExpired($now->modify('+10 seconds')));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter GoogleAccountTest
```
Attendu : classe manquante.

- [ ] **Step 3 : Créer `src/Domain/GoogleAccount.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class GoogleAccount
{
    private function __construct(
        private ?int $id,
        private string $label,
        private string $calendarId,
        private string $refreshTokenEnc,
        private string $accessTokenEnc,
        private DateTimeImmutable $expiresAt,
        private ?string $watchChannelId,
        private ?string $watchResourceId,
        private ?string $watchTokenSecret,
        private ?DateTimeImmutable $watchExpiresAt,
        private ?string $syncToken,
        private ?DateTimeImmutable $lastFullSyncAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function connect(
        string $label,
        string $calendarId,
        string $refreshTokenEnc,
        string $accessTokenEnc,
        DateTimeImmutable $expiresAt,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: null,
            label: $label,
            calendarId: $calendarId,
            refreshTokenEnc: $refreshTokenEnc,
            accessTokenEnc: $accessTokenEnc,
            expiresAt: $expiresAt,
            watchChannelId: null,
            watchResourceId: null,
            watchTokenSecret: null,
            watchExpiresAt: null,
            syncToken: null,
            lastFullSyncAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');
        $parse = static function (?string $s) use ($utc): ?DateTimeImmutable {
            return $s === null ? null : new DateTimeImmutable($s, $utc);
        };
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            calendarId: (string) $row['calendar_id'],
            refreshTokenEnc: (string) ($row['oauth_refresh_token_enc'] ?? ''),
            accessTokenEnc: (string) ($row['oauth_access_token_enc'] ?? ''),
            expiresAt: $parse($row['oauth_expires_at'] ?? null) ?? new DateTimeImmutable('1970-01-01', $utc),
            watchChannelId: $row['watch_channel_id'] !== null ? (string) $row['watch_channel_id'] : null,
            watchResourceId: $row['watch_resource_id'] !== null ? (string) $row['watch_resource_id'] : null,
            watchTokenSecret: $row['watch_token_secret'] !== null ? (string) $row['watch_token_secret'] : null,
            watchExpiresAt: $parse($row['watch_expires_at'] ?? null),
            syncToken: $row['sync_token'] !== null ? (string) $row['sync_token'] : null,
            lastFullSyncAt: $parse($row['last_full_sync_at'] ?? null),
            createdAt: $parse((string) $row['created_at']) ?? new DateTimeImmutable('now', $utc),
            updatedAt: $parse((string) $row['updated_at']) ?? new DateTimeImmutable('now', $utc),
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new \DomainException('GoogleAccount id already assigned.');
        }
        $this->id = $id;
    }

    public function rotateAccessToken(string $accessTokenEnc, DateTimeImmutable $expiresAt): void
    {
        $this->accessTokenEnc = $accessTokenEnc;
        $this->expiresAt = $expiresAt;
        $this->touch();
    }

    public function accessTokenExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function id(): ?int { return $this->id; }
    public function label(): string { return $this->label; }
    public function calendarId(): string { return $this->calendarId; }
    public function refreshTokenEnc(): string { return $this->refreshTokenEnc; }
    public function accessTokenEnc(): string { return $this->accessTokenEnc; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function watchChannelId(): ?string { return $this->watchChannelId; }
    public function watchExpiresAt(): ?DateTimeImmutable { return $this->watchExpiresAt; }
    public function syncToken(): ?string { return $this->syncToken; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test
```

- [ ] **Step 5 : Commit**

```bash
git add src/Domain/GoogleAccount.php tests/Unit/Domain/GoogleAccountTest.php
git commit -m "feat(domain): GoogleAccount value object with token rotation"
```

---

## Task 6 : `Persistence\GoogleAccountRepository`

**Files:**
- Create: `src/Persistence/GoogleAccountRepository.php`
- Create: `tests/Integration/GoogleAccountRepositoryTest.php`

V1 mono-compte → on expose `findSingle()` qui retourne le seul compte ou `null`. Le schéma reste multi-compte (préparé Plan 1).

- [ ] **Step 1 : Écrire le test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class GoogleAccountRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \wpdb)) {
            $this->markTestSkipped('Requires wp-phpunit (run via composer test:integration).');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_google_accounts");
    }

    public function test_save_then_find_single_returns_account(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('Commercial', 'primary', 'enc-refresh', 'enc-access', $now->modify('+1 hour'));

        $repo->save($acct);
        self::assertNotNull($acct->id());

        $found = $repo->findSingle();
        self::assertNotNull($found);
        self::assertSame('primary', $found->calendarId());
        self::assertSame('enc-refresh', $found->refreshTokenEnc());
    }

    public function test_save_updates_existing(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r1', 'a1', $now->modify('+1 hour'));
        $repo->save($acct);

        $acct->rotateAccessToken('a2', $now->modify('+2 hours'));
        $repo->save($acct);

        $found = $repo->findSingle();
        self::assertSame('a2', $found?->accessTokenEnc());
    }

    public function test_delete_removes(): void
    {
        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $acct = GoogleAccount::connect('x', 'primary', 'r', 'a', $now);
        $repo->save($acct);
        $repo->delete((int) $acct->id());
        self::assertNull($repo->findSingle());
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test:integration -- --filter GoogleAccountRepositoryTest
```
Attendu : skipped si wp-phpunit absent, sinon classe manquante.

- [ ] **Step 3 : Créer `src/Persistence/GoogleAccountRepository.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\GoogleAccount;
use wpdb;

final class GoogleAccountRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_google_accounts';
    }

    public function save(GoogleAccount $account): void
    {
        $row = $this->toRow($account);
        if ($account->id() === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->wpdb->insert($this->table, $row);
            $account->assignId((int) $this->wpdb->insert_id);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->update($this->table, $row, ['id' => $account->id()]);
    }

    public function findSingle(): ?GoogleAccount
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row("SELECT * FROM {$this->table} ORDER BY id ASC LIMIT 1", ARRAY_A);
        return is_array($row) ? GoogleAccount::fromRow($row) : null;
    }

    public function findById(int $id): ?GoogleAccount
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? GoogleAccount::fromRow($row) : null;
    }

    public function delete(int $id): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->delete($this->table, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(GoogleAccount $a): array
    {
        $fmt = static fn (?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d H:i:s');
        return [
            'label'                    => $a->label(),
            'calendar_id'              => $a->calendarId(),
            'oauth_refresh_token_enc'  => $a->refreshTokenEnc(),
            'oauth_access_token_enc'   => $a->accessTokenEnc(),
            'oauth_expires_at'         => $fmt($a->expiresAt()),
            'watch_channel_id'         => $a->watchChannelId(),
            'watch_expires_at'         => $fmt($a->watchExpiresAt()),
            'sync_token'               => $a->syncToken(),
            'created_at'               => $fmt($a->createdAt()),
            'updated_at'               => $fmt($a->updatedAt()),
        ];
    }
}
```

- [ ] **Step 4 : Lancer → vert (ou skipped propre)**

```bash
composer test:integration -- --filter GoogleAccountRepositoryTest
```

- [ ] **Step 5 : Commit**

```bash
git add src/Persistence/GoogleAccountRepository.php tests/Integration/GoogleAccountRepositoryTest.php
git commit -m "feat(persistence): GoogleAccountRepository (mono-account V1)"
```

---

## Task 7 : `Google\OAuthState` — HMAC CSRF state token

**Files:**
- Create: `src/Google/OAuthState.php`
- Create: `tests/Unit/Google/OAuthStateTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Google\OAuthState;

final class OAuthStateTest extends TestCase
{
    private OAuthState $state;

    protected function setUp(): void
    {
        $this->state = new OAuthState('test-secret-32-bytes-long-aaaaaa');
    }

    public function test_issue_then_verify(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $userId = $this->state->verify($token, now: 1_700_000_000);
        self::assertSame(1, $userId);
    }

    public function test_verify_expired_returns_null(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $userId = $this->state->verify($token, now: 1_700_000_000 + 601);
        self::assertNull($userId);
    }

    public function test_verify_tampered_returns_null(): void
    {
        $token = $this->state->issue(userId: 1, now: 1_700_000_000);
        $tampered = substr($token, 0, -2) . 'xx';
        self::assertNull($this->state->verify($tampered, now: 1_700_000_000));
    }

    public function test_verify_malformed_returns_null(): void
    {
        self::assertNull($this->state->verify('garbage', now: 1_700_000_000));
        self::assertNull($this->state->verify('a|b|c', now: 1_700_000_000));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

- [ ] **Step 3 : Créer `src/Google/OAuthState.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

final class OAuthState
{
    public const TTL_SECONDS = 600;

    public function __construct(private readonly string $secret)
    {
    }

    public function issue(int $userId, ?int $now = null): string
    {
        $now ??= time();
        $exp = $now + self::TTL_SECONDS;
        $payload = sprintf('oauth|%d|%d', $userId, $exp);
        $sig = hash_hmac('sha256', $payload, $this->secret);
        return base64_encode($payload . '|' . $sig);
    }

    public function verify(string $token, ?int $now = null): ?int
    {
        $now ??= time();
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }
        $parts = explode('|', $decoded);
        if (count($parts) !== 4 || $parts[0] !== 'oauth') {
            return null;
        }
        [, $userIdStr, $expStr, $sig] = $parts;
        if (!ctype_digit($userIdStr) || !ctype_digit($expStr)) {
            return null;
        }
        $exp = (int) $expStr;
        if ($exp < $now) {
            return null;
        }
        $expected = hash_hmac('sha256', "oauth|{$userIdStr}|{$expStr}", $this->secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return (int) $userIdStr;
    }
}
```

- [ ] **Step 4 : Lancer → vert**

- [ ] **Step 5 : Commit**

```bash
git add src/Google/OAuthState.php tests/Unit/Google/OAuthStateTest.php
git commit -m "feat(google): HMAC-signed OAuth state token (10min TTL)"
```

---

## Task 8 : Exceptions Google + `OAuthClient` (échange code, refresh)

**Files:**
- Create: `src/Google/Exceptions/OAuthFailure.php`
- Create: `src/Google/Exceptions/GoogleApiError.php`
- Create: `src/Google/Exceptions/GoogleClientError.php`
- Create: `src/Google/OAuthClient.php`

`OAuthClient` est une classe fine qui parle directement à l'endpoint `https://oauth2.googleapis.com/token` via `wp_remote_post` — pas besoin du SDK Google pour le token exchange. Cela rend les tests d'intégration triviaux (mock `pre_http_request`).

- [ ] **Step 1 : Créer les exceptions**

`src/Google/Exceptions/OAuthFailure.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google\Exceptions;

final class OAuthFailure extends \RuntimeException
{
}
```

`src/Google/Exceptions/GoogleApiError.php` (transient — retry) :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google\Exceptions;

final class GoogleApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
```

`src/Google/Exceptions/GoogleClientError.php` (déterministe — pas de retry) :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google\Exceptions;

final class GoogleClientError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 2 : Créer `src/Google/OAuthClient.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Trinity\Booking\Google\Exceptions\OAuthFailure;

/**
 * @phpstan-type TokenResponse array{
 *   access_token: string,
 *   refresh_token?: string,
 *   expires_in: int,
 *   scope?: string,
 *   token_type?: string
 * }
 */
final class OAuthClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {
    }

    public function authUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
            'include_granted_scopes' => 'true',
        ];
        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * @return TokenResponse
     */
    public function exchangeCode(string $code): array
    {
        return $this->post([
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
    }

    /**
     * @return TokenResponse
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->post([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
    }

    /**
     * @param array<string, string> $body
     * @return TokenResponse
     */
    private function post(array $body): array
    {
        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            throw new OAuthFailure('HTTP error: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $json   = json_decode($raw, true);

        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $err = is_array($json) && isset($json['error']) ? (string) $json['error'] : 'unknown';
            throw new OAuthFailure(sprintf('Google token endpoint returned %d (%s).', $status, $err));
        }

        if (!isset($json['access_token'], $json['expires_in'])) {
            throw new OAuthFailure('Google token response missing fields.');
        }

        /** @var TokenResponse $json */
        return $json;
    }
}
```

- [ ] **Step 3 : Commit**

```bash
git add src/Google/Exceptions/ src/Google/OAuthClient.php
git commit -m "feat(google): OAuthClient + Google error hierarchy"
```

---

## Task 9 : `Google\CalendarGateway` interface + `FakeCalendarGateway` test double

**Files:**
- Create: `src/Google/CalendarGateway.php`
- Create: `tests/Unit/Support/FakeCalendarGateway.php`

- [ ] **Step 1 : Créer l'interface**

`src/Google/CalendarGateway.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

/**
 * @phpstan-type EventPayload array{
 *   summary: string,
 *   description?: string,
 *   start: array{dateTime: string, timeZone: string},
 *   end: array{dateTime: string, timeZone: string},
 *   colorId?: string,
 *   attendees?: list<array{email: string}>
 * }
 * @phpstan-type EventRef array{id: string, etag: string}
 */
interface CalendarGateway
{
    /**
     * @param EventPayload $payload
     * @return EventRef
     */
    public function insertEvent(string $calendarId, array $payload): array;

    /**
     * @param EventPayload $payload
     * @return EventRef
     */
    public function patchEvent(string $calendarId, string $eventId, array $payload): array;

    public function deleteEvent(string $calendarId, string $eventId): void;
}
```

- [ ] **Step 2 : Créer `tests/Unit/Support/FakeCalendarGateway.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Support;

use Trinity\Booking\Google\CalendarGateway;
use Trinity\Booking\Google\Exceptions\GoogleApiError;

final class FakeCalendarGateway implements CalendarGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $events = [];

    /** @var list<array{op:string, calendar:string, payload:mixed, eventId?:string}> */
    public array $calls = [];

    private int $seq = 0;

    public bool $failNext = false;

    public function insertEvent(string $calendarId, array $payload): array
    {
        $this->calls[] = ['op' => 'insert', 'calendar' => $calendarId, 'payload' => $payload];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        $id = 'evt_' . (++$this->seq);
        $etag = '"etag_' . $this->seq . '"';
        $this->events[$id] = ['etag' => $etag, 'payload' => $payload];
        return ['id' => $id, 'etag' => $etag];
    }

    public function patchEvent(string $calendarId, string $eventId, array $payload): array
    {
        $this->calls[] = ['op' => 'patch', 'calendar' => $calendarId, 'eventId' => $eventId, 'payload' => $payload];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        if (!isset($this->events[$eventId])) {
            throw new \Trinity\Booking\Google\Exceptions\GoogleClientError('Event not found', 404);
        }
        $this->events[$eventId]['payload'] = $payload;
        $this->events[$eventId]['etag'] = '"etag_patched_' . (++$this->seq) . '"';
        return ['id' => $eventId, 'etag' => $this->events[$eventId]['etag']];
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->calls[] = ['op' => 'delete', 'calendar' => $calendarId, 'eventId' => $eventId];
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        unset($this->events[$eventId]);
    }
}
```

- [ ] **Step 3 : Mettre à jour `composer.json` pour autoload de `tests/Unit/Support/`**

Vérifier que `"autoload-dev"` inclut bien `"Trinity\\Booking\\Tests\\": "tests/"`. C'est déjà le cas — donc rien à faire. Régénérer :

```bash
composer dump-autoload
```

- [ ] **Step 4 : Commit**

```bash
git add src/Google/CalendarGateway.php tests/Unit/Support/FakeCalendarGateway.php
git commit -m "feat(google): CalendarGateway interface + in-memory fake"
```

---

## Task 10 : `Google\EventFormatter` — Booking → Google event payload

**Files:**
- Create: `src/Google/EventFormatter.php`
- Create: `tests/Unit/Google/EventFormatterTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Google\EventFormatter;

final class EventFormatterTest extends TestCase
{
    private function service(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000'
        );
    }

    private function pending(): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T10:00:00', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-06-01T11:30:00', new DateTimeZone('Europe/Paris')),
        );
        return Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean Dupont', customerEmail: 'j@x.fr',
            customerPhone: '0600000000', customerAddress: '1 rue X',
            customerMeta: [], notes: 'Voir étage 2',
        );
    }

    public function test_pending_format_uses_orange_color_and_prefix(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $b = $this->pending();
        $payload = $f->format($b, $this->service());

        self::assertStringStartsWith('[À VALIDER] Photovoltaïque · Jean Dupont', $payload['summary']);
        self::assertSame('6', $payload['colorId']);
        self::assertSame('2026-06-01T10:00:00+02:00', $payload['start']['dateTime']);
        self::assertSame('Europe/Paris', $payload['start']['timeZone']);
        self::assertSame('2026-06-01T11:30:00+02:00', $payload['end']['dateTime']);
        self::assertStringContainsString('j@x.fr', $payload['description']);
        self::assertStringContainsString('0600000000', $payload['description']);
        self::assertStringContainsString('1 rue X', $payload['description']);
        self::assertStringContainsString('Voir étage 2', $payload['description']);
    }

    public function test_confirmed_format_uses_green_color_no_prefix(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $b = $this->pending();
        $b->assignId(7);
        $b->confirm();

        $payload = $f->format($b, $this->service());

        self::assertSame('Photovoltaïque · Jean Dupont', $payload['summary']);
        self::assertSame('10', $payload['colorId']);
    }

    public function test_description_escapes_html(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T10:00:00', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-06-01T11:30:00', new DateTimeZone('Europe/Paris')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: '<script>x</script>', customerEmail: 'a@b.fr',
            customerPhone: '0', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $payload = $f->format($b, $this->service());
        self::assertStringNotContainsString('<script>', $payload['description']);
        self::assertStringContainsString('&lt;script&gt;', $payload['description']);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

- [ ] **Step 3 : Créer `src/Google/EventFormatter.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\Service;

final class EventFormatter
{
    public const PENDING_PREFIX = '[À VALIDER] ';

    public function __construct(
        private readonly string $pendingColorId,
        private readonly string $confirmedColorId,
    ) {
    }

    /**
     * @return array{
     *   summary: string,
     *   description: string,
     *   start: array{dateTime: string, timeZone: string},
     *   end: array{dateTime: string, timeZone: string},
     *   colorId: string
     * }
     */
    public function format(Booking $booking, Service $service): array
    {
        $isPending = $booking->status() === BookingStatus::PENDING;
        $prefix    = $isPending ? self::PENDING_PREFIX : '';
        $summary   = $prefix . $service->name . ' · ' . $booking->customerName();

        $tz = $booking->timezone();
        $start = $booking->slot()->start->setTimezone(new \DateTimeZone($tz));
        $end   = $booking->slot()->end->setTimezone(new \DateTimeZone($tz));

        return [
            'summary'     => $summary,
            'description' => $this->description($booking),
            'start'       => [
                'dateTime' => $start->format('Y-m-d\TH:i:sP'),
                'timeZone' => $tz,
            ],
            'end'         => [
                'dateTime' => $end->format('Y-m-d\TH:i:sP'),
                'timeZone' => $tz,
            ],
            'colorId'     => $isPending ? $this->pendingColorId : $this->confirmedColorId,
        ];
    }

    private function description(Booking $b): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [
            'Client : ' . $esc($b->customerName()),
            'E-mail : ' . $esc($b->customerEmail()),
            'Téléphone : ' . $esc($b->customerPhone()),
        ];
        if ($b->customerAddress() !== '') {
            $lines[] = 'Adresse : ' . $esc($b->customerAddress());
        }
        if ($b->notes() !== '') {
            $lines[] = '';
            $lines[] = 'Notes : ' . $esc($b->notes());
        }
        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4 : Lancer → vert**

- [ ] **Step 5 : Commit**

```bash
git add src/Google/EventFormatter.php tests/Unit/Google/EventFormatterTest.php
git commit -m "feat(google): EventFormatter maps Booking to Google Calendar payload"
```

---

## Task 11 : `Persistence\SyncLogRepository`

**Files:**
- Create: `src/Persistence/SyncLogRepository.php`
- Create: `tests/Integration/SyncLogRepositoryTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Persistence\SyncLogRepository;

final class SyncLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($GLOBALS['wpdb']) || !($GLOBALS['wpdb'] instanceof \wpdb)) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");
    }

    public function test_append_then_paginate(): void
    {
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);

        for ($i = 1; $i <= 3; $i++) {
            $repo->append(
                level: 'info',
                direction: 'wp_to_g',
                entity: 'booking',
                entityId: $i,
                googleEventId: 'evt_' . $i,
                action: 'create',
                status: 'ok',
                payload: ['n' => $i],
                errorMessage: null,
            );
        }

        $page = $repo->paginate([], 1, 10);
        self::assertSame(3, $page['total']);
        self::assertCount(3, $page['items']);
        self::assertSame('evt_3', $page['items'][0]['google_event_id']);
    }

    public function test_purge_older_than(): void
    {
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);
        $repo->append('info', 'wp_to_g', 'booking', 1, null, 'create', 'ok', [], null);
        // Force-age the row 40 days back
        $wpdb->query("UPDATE {$wpdb->prefix}tb_sync_log SET ts = DATE_SUB(NOW(), INTERVAL 40 DAY)");

        $repo->append('info', 'wp_to_g', 'booking', 2, null, 'create', 'ok', [], null);

        $deleted = $repo->purgeOlderThan(new DateTimeImmutable('-30 days', new DateTimeZone('UTC')));
        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->paginate([], 1, 10)['total']);
    }
}
```

- [ ] **Step 2 : Lancer → rouge ou skipped**

- [ ] **Step 3 : Créer `src/Persistence/SyncLogRepository.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final class SyncLogRepository
{
    private const PAYLOAD_MAX = 4096;

    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_sync_log';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(
        string $level,
        string $direction,
        string $entity,
        ?int $entityId,
        ?string $googleEventId,
        string $action,
        string $status,
        array $payload,
        ?string $errorMessage,
    ): void {
        $json = (string) wp_json_encode($payload);
        if (strlen($json) > self::PAYLOAD_MAX) {
            $json = substr($json, 0, self::PAYLOAD_MAX) . '…[truncated]';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->insert($this->table, [
            'ts'              => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'level'           => $level,
            'direction'       => $direction,
            'entity'          => $entity,
            'entity_id'       => $entityId,
            'google_event_id' => $googleEventId,
            'action'          => $action,
            'payload'         => $json,
            'status'          => $status,
            'error_message'   => $errorMessage,
        ]);
    }

    /**
     * @param array{level?:string, direction?:string, status?:string, entity_id?:int} $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $where   = [];
        $args    = [];

        foreach (['level', 'direction', 'status'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "{$col} = %s";
                $args[]  = (string) $filters[$col];
            }
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = %d';
            $args[]  = (int) $filters['entity_id'];
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $totalSql = "SELECT COUNT(*) FROM {$this->table}" . $whereSql;
        $total = (int) $this->wpdb->get_var(
            $args === [] ? $totalSql : $this->wpdb->prepare($totalSql, ...$args)
        );

        $listSql = "SELECT * FROM {$this->table}" . $whereSql .
            ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($listSql, ...array_merge($args, [$perPage, ($page - 1) * $perPage])),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return [
            'items'    => is_array($rows) ? $rows : [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE ts < %s",
                $cutoff->format('Y-m-d H:i:s')
            )
        );
        return is_int($rows) ? $rows : 0;
    }
}
```

- [ ] **Step 4 : Lancer → vert ou skipped**

- [ ] **Step 5 : Commit**

```bash
git add src/Persistence/SyncLogRepository.php tests/Integration/SyncLogRepositoryTest.php
git commit -m "feat(persistence): SyncLogRepository (append, paginate, purge)"
```

---

## Task 12 : `Google\GoogleApiCalendarGateway` — impl SDK

**Files:**
- Create: `src/Google/GoogleApiCalendarGateway.php`

Pas de test unitaire dédié : c'est un thin wrapper. Vérification manuelle via `wp trinity-booking doctor` (Task 21).

- [ ] **Step 1 : Créer la classe**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Google\Client as GoogleClient;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Event as CalendarEvent;
use Google\Service\Exception as GoogleServiceException;
use Trinity\Booking\Google\Exceptions\GoogleApiError;
use Trinity\Booking\Google\Exceptions\GoogleClientError;

final class GoogleApiCalendarGateway implements CalendarGateway
{
    private CalendarService $service;

    public function __construct(GoogleClient $client)
    {
        $this->service = new CalendarService($client);
    }

    public function insertEvent(string $calendarId, array $payload): array
    {
        return $this->call(function () use ($calendarId, $payload): array {
            $event = new CalendarEvent($payload);
            $created = $this->service->events->insert($calendarId, $event);
            return ['id' => (string) $created->getId(), 'etag' => (string) $created->getEtag()];
        });
    }

    public function patchEvent(string $calendarId, string $eventId, array $payload): array
    {
        return $this->call(function () use ($calendarId, $eventId, $payload): array {
            $event = new CalendarEvent($payload);
            $updated = $this->service->events->patch($calendarId, $eventId, $event);
            return ['id' => (string) $updated->getId(), 'etag' => (string) $updated->getEtag()];
        });
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->call(function () use ($calendarId, $eventId): array {
            $this->service->events->delete($calendarId, $eventId);
            return ['id' => $eventId, 'etag' => ''];
        });
    }

    /**
     * @param callable(): array{id: string, etag: string} $fn
     * @return array{id: string, etag: string}
     */
    private function call(callable $fn): array
    {
        try {
            return $fn();
        } catch (GoogleServiceException $e) {
            $code = $e->getCode();
            $msg  = $e->getMessage();
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google API transient error ({$code}): {$msg}", $code);
            }
            throw new GoogleClientError("Google API client error ({$code}): {$msg}", $code);
        } catch (\Throwable $e) {
            throw new GoogleApiError('Unexpected Google API error: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2 : Vérifier qu'il compile**

```bash
composer stan
```
Attendu : 0 erreur.

- [ ] **Step 3 : Commit**

```bash
git add src/Google/GoogleApiCalendarGateway.php
git commit -m "feat(google): CalendarGateway implementation backed by google/apiclient"
```

---

## Task 13 : `Google\PushScheduler` — hooks → Action Scheduler

**Files:**
- Create: `src/Google/PushScheduler.php`
- Create: `tests/Unit/Google/PushSchedulerTest.php`

`PushScheduler` écoute les mêmes hooks WP que `BookingNotifier`. Pour chaque transition, il enfile un job. Si Action Scheduler n'est pas disponible, on logue un warning et on retombe sur un appel synchrone (utile pour les tests / configurations dégradées). En tests unitaires, on injecte une closure d'enqueue mockable.

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Google\PushScheduler;

final class PushSchedulerTest extends TestCase
{
    public function test_on_created_enqueues_create_action(): void
    {
        $calls = [];
        $enqueue = function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        };
        $scheduler = new PushScheduler($enqueue);
        $scheduler->onCreated(42);

        self::assertSame([['tb/push_gcal_event', [42, 'create']]], $calls);
    }

    public function test_on_confirmed_enqueues_confirm(): void
    {
        $calls = [];
        $scheduler = new PushScheduler(function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        });
        $scheduler->onConfirmed(42);
        self::assertSame([['tb/push_gcal_event', [42, 'confirm']]], $calls);
    }

    public function test_on_rejected_and_cancelled_enqueue_delete(): void
    {
        $calls = [];
        $enq = function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        };
        $s = new PushScheduler($enq);
        $s->onRejected(42);
        $s->onCancelled(43);
        self::assertSame([
            ['tb/push_gcal_event', [42, 'delete']],
            ['tb/push_gcal_event', [43, 'delete']],
        ], $calls);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

- [ ] **Step 3 : Créer `src/Google/PushScheduler.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Closure;

final class PushScheduler
{
    public const HOOK = 'tb/push_gcal_event';

    /** @var Closure(string, array<int, mixed>): void */
    private Closure $enqueue;

    /**
     * @param (Closure(string, array<int, mixed>): void)|null $enqueue
     */
    public function __construct(?Closure $enqueue = null)
    {
        $this->enqueue = $enqueue ?? self::defaultEnqueue();
    }

    public function register(): void
    {
        add_action('trinity_booking/booking_created',   [$this, 'onCreated'],   20, 1);
        add_action('trinity_booking/booking_confirmed', [$this, 'onConfirmed'], 20, 1);
        add_action('trinity_booking/booking_rejected',  [$this, 'onRejected'],  20, 1);
        add_action('trinity_booking/booking_cancelled', [$this, 'onCancelled'], 20, 1);
    }

    public function onCreated(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'create']);
    }

    public function onConfirmed(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'confirm']);
    }

    public function onRejected(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'delete']);
    }

    public function onCancelled(int $bookingId): void
    {
        ($this->enqueue)(self::HOOK, [$bookingId, 'delete']);
    }

    /**
     * @return Closure(string, array<int, mixed>): void
     */
    private static function defaultEnqueue(): Closure
    {
        return static function (string $hook, array $args): void {
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action($hook, $args, 'trinity-booking');
                return;
            }
            // Fallback synchronous (Action Scheduler not loaded — should not happen in production).
            if (function_exists('do_action')) {
                do_action($hook, ...$args);
            }
        };
    }
}
```

- [ ] **Step 4 : Lancer → vert**

- [ ] **Step 5 : Commit**

```bash
git add src/Google/PushScheduler.php tests/Unit/Google/PushSchedulerTest.php
git commit -m "feat(google): PushScheduler enqueues async sync jobs on booking hooks"
```

---

## Task 14 : `Google\PushEventJob` — create / confirm / delete handler

**Files:**
- Create: `src/Google/PushEventJob.php`
- Create: `tests/Unit/Google/PushEventJobTest.php`

C'est la pièce centrale. Le job reçoit `[bookingId, action]`, charge le booking + compte Google, formate, appelle la gateway, met à jour `google_event_id` + `etag` sur le booking, logue. Gère l'idempotence et les fallbacks (confirm sans event → create).

- [ ] **Step 1 : Écrire le test (création)**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Google\EventFormatter;
use Trinity\Booking\Google\PushEventJob;
use Trinity\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class PushEventJobTest extends TestCase
{
    private FakeCalendarGateway $gateway;
    private EventFormatter $formatter;

    /** @var list<array<string, mixed>> */
    private array $log;

    protected function setUp(): void
    {
        $this->gateway = new FakeCalendarGateway();
        $this->formatter = new EventFormatter('6', '10');
        $this->log = [];
    }

    private function service(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000'
        );
    }

    private function pending(int $id): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T10:00:00', new DateTimeZone('Europe/Paris')),
            new DateTimeImmutable('2026-06-01T11:30:00', new DateTimeZone('Europe/Paris')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $b->assignId($id);
        return $b;
    }

    private function account(): GoogleAccount
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $a = GoogleAccount::connect('x', 'primary', 'enc-r', 'enc-a', $now->modify('+1 hour'));
        $a->assignId(1);
        return $a;
    }

    private function job(Booking $b, ?GoogleAccount $a = null): PushEventJob
    {
        $a = $a ?? $this->account();
        $persisted = $b;
        return new PushEventJob(
            findBooking: fn () => $b,
            findAccount: fn () => $a,
            persistBooking: function (Booking $bb) use (&$persisted): void { $persisted = $bb; },
            gateway: $this->gateway,
            formatter: $this->formatter,
            service: $this->service(),
            log: function (array $entry): void { $this->log[] = $entry; },
        );
    }

    public function test_create_inserts_event_and_persists_id_etag(): void
    {
        $b = $this->pending(42);
        $this->job($b)->handle(42, 'create');

        self::assertCount(1, $this->gateway->calls);
        self::assertSame('insert', $this->gateway->calls[0]['op']);
        self::assertNotNull($b->googleEventId());
        self::assertNotNull($b->googleEventEtag());
        self::assertSame('ok', $this->log[0]['status']);
    }

    public function test_create_is_idempotent_when_event_already_set(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_existing', '"etag_x"');
        $this->job($b)->handle(42, 'create');

        self::assertSame([], $this->gateway->calls);
        self::assertSame('evt_existing', $b->googleEventId());
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

- [ ] **Step 3 : Créer `src/Google/PushEventJob.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Closure;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Google\Exceptions\GoogleApiError;
use Trinity\Booking\Google\Exceptions\GoogleClientError;

final class PushEventJob
{
    /**
     * @param Closure(int): ?Booking         $findBooking
     * @param Closure(): ?GoogleAccount      $findAccount
     * @param Closure(Booking): void         $persistBooking
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly Closure $findBooking,
        private readonly Closure $findAccount,
        private readonly Closure $persistBooking,
        private readonly CalendarGateway $gateway,
        private readonly EventFormatter $formatter,
        private readonly Service $service,
        private readonly Closure $log,
    ) {
    }

    public function handle(int $bookingId, string $action): void
    {
        $booking = ($this->findBooking)($bookingId);
        if ($booking === null) {
            $this->logEntry($bookingId, $action, 'failed', null, 'Booking not found');
            return;
        }

        $account = ($this->findAccount)();
        if ($account === null) {
            $this->logEntry($bookingId, $action, 'failed', null, 'No Google account connected');
            return;
        }

        try {
            match ($action) {
                'create'  => $this->doCreate($booking, $account),
                'confirm' => $this->doConfirm($booking, $account),
                'delete'  => $this->doDelete($booking, $account),
                default   => $this->logEntry($bookingId, $action, 'failed', null, 'Unknown action'),
            };
        } catch (GoogleClientError $e) {
            // 4xx — deterministic. Log and swallow (no retry).
            $this->logEntry($bookingId, $action, 'failed', $booking->googleEventId(), $e->getMessage());
        } catch (GoogleApiError $e) {
            // 5xx — let Action Scheduler retry.
            $this->logEntry($bookingId, $action, 'retry', $booking->googleEventId(), $e->getMessage());
            throw $e;
        }
    }

    private function doCreate(Booking $booking, GoogleAccount $account): void
    {
        if ($booking->googleEventId() !== null) {
            // Already created — idempotent no-op.
            $this->logEntry((int) $booking->id(), 'create', 'ok', $booking->googleEventId(), 'noop (already created)');
            return;
        }
        $payload = $this->formatter->format($booking, $this->service);
        $ref = $this->gateway->insertEvent($account->calendarId(), $payload);
        $booking->setGoogleEvent($ref['id'], $ref['etag']);
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'create', 'ok', $ref['id'], null);
    }

    private function doConfirm(Booking $booking, GoogleAccount $account): void
    {
        $payload = $this->formatter->format($booking, $this->service);
        $eventId = $booking->googleEventId();
        if ($eventId === null) {
            // Fallback: event was never created. Create it now as confirmed.
            $ref = $this->gateway->insertEvent($account->calendarId(), $payload);
            $booking->setGoogleEvent($ref['id'], $ref['etag']);
            ($this->persistBooking)($booking);
            $this->logEntry((int) $booking->id(), 'create', 'ok', $ref['id'], 'fallback from confirm');
            return;
        }
        $ref = $this->gateway->patchEvent($account->calendarId(), $eventId, $payload);
        $booking->setGoogleEvent($ref['id'], $ref['etag']);
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'update', 'ok', $ref['id'], null);
    }

    private function doDelete(Booking $booking, GoogleAccount $account): void
    {
        $eventId = $booking->googleEventId();
        if ($eventId === null) {
            $this->logEntry((int) $booking->id(), 'delete', 'ok', null, 'noop (no event)');
            return;
        }
        try {
            $this->gateway->deleteEvent($account->calendarId(), $eventId);
        } catch (GoogleClientError $e) {
            if ($e->httpStatus !== 410 && $e->httpStatus !== 404) {
                throw $e;
            }
            // Already gone — treat as success.
        }
        $booking->clearGoogleEvent();
        ($this->persistBooking)($booking);
        $this->logEntry((int) $booking->id(), 'delete', 'ok', $eventId, null);
    }

    private function logEntry(int $bookingId, string $action, string $status, ?string $eventId, ?string $error): void
    {
        ($this->log)([
            'level'           => $status === 'failed' ? 'error' : ($status === 'retry' ? 'warn' : 'info'),
            'direction'       => 'wp_to_g',
            'entity'          => 'booking',
            'entity_id'       => $bookingId,
            'google_event_id' => $eventId,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
        ]);
    }
}
```

- [ ] **Step 4 : Mettre à jour `Booking` — méthode `clearGoogleEvent()`**

Dans `src/Domain/Booking.php`, ajouter après `setGoogleEvent()` :

```php
public function clearGoogleEvent(): void
{
    $this->googleEventId = null;
    $this->googleEventEtag = null;
    $this->touch();
}
```

- [ ] **Step 5 : Lancer → vert**

```bash
composer test
```

- [ ] **Step 6 : Commit**

```bash
git add src/Google/PushEventJob.php src/Domain/Booking.php tests/Unit/Google/PushEventJobTest.php
git commit -m "feat(google): PushEventJob handles create/confirm/delete with retry semantics"
```

---

## Task 15 : `PushEventJob` — tests confirm + delete + retry

**Files:**
- Modify: `tests/Unit/Google/PushEventJobTest.php`

- [ ] **Step 1 : Ajouter les tests**

Dans `PushEventJobTest`, ajouter :

```php
public function test_confirm_patches_existing_event(): void
{
    $b = $this->pending(42);
    $b->setGoogleEvent('evt_1', '"etag_old"');
    $b->confirm();

    $this->gateway->events['evt_1'] = ['etag' => '"etag_old"', 'payload' => []];

    $this->job($b)->handle(42, 'confirm');

    self::assertSame('patch', $this->gateway->calls[0]['op']);
    self::assertSame('evt_1', $b->googleEventId());
    self::assertStringStartsWith('"etag_patched_', $b->googleEventEtag() ?? '');
}

public function test_confirm_falls_back_to_create_when_no_event(): void
{
    $b = $this->pending(42);
    $b->confirm();
    $this->job($b)->handle(42, 'confirm');

    self::assertSame('insert', $this->gateway->calls[0]['op']);
    self::assertNotNull($b->googleEventId());
}

public function test_delete_calls_gateway_and_clears_event(): void
{
    $b = $this->pending(42);
    $b->setGoogleEvent('evt_to_delete', '"etag_x"');
    $this->gateway->events['evt_to_delete'] = ['etag' => '"etag_x"', 'payload' => []];

    $this->job($b)->handle(42, 'delete');

    self::assertSame('delete', $this->gateway->calls[0]['op']);
    self::assertNull($b->googleEventId());
}

public function test_delete_noop_when_no_event_set(): void
{
    $b = $this->pending(42);
    $this->job($b)->handle(42, 'delete');
    self::assertSame([], $this->gateway->calls);
    self::assertSame('ok', $this->log[0]['status']);
}

public function test_transient_5xx_is_logged_as_retry_and_rethrown(): void
{
    $b = $this->pending(42);
    $this->gateway->failNext = true;
    $this->expectException(\Trinity\Booking\Google\Exceptions\GoogleApiError::class);
    try {
        $this->job($b)->handle(42, 'create');
    } finally {
        self::assertSame('retry', $this->log[0]['status']);
    }
}

public function test_unknown_booking_logs_failed(): void
{
    $persisted = $this->pending(42); // unused, just for shape
    $job = new PushEventJob(
        findBooking: fn () => null,
        findAccount: fn () => $this->account(),
        persistBooking: fn () => null,
        gateway: $this->gateway,
        formatter: $this->formatter,
        service: $this->service(),
        log: function (array $e): void { $this->log[] = $e; },
    );
    $job->handle(999, 'create');
    self::assertSame('failed', $this->log[0]['status']);
}

public function test_no_account_logs_failed(): void
{
    $b = $this->pending(42);
    $job = new PushEventJob(
        findBooking: fn () => $b,
        findAccount: fn () => null,
        persistBooking: fn () => null,
        gateway: $this->gateway,
        formatter: $this->formatter,
        service: $this->service(),
        log: function (array $e): void { $this->log[] = $e; },
    );
    $job->handle(42, 'create');
    self::assertSame('failed', $this->log[0]['status']);
    self::assertStringContainsString('No Google account', (string) $this->log[0]['error_message']);
}
```

- [ ] **Step 2 : Lancer → vert**

```bash
composer test
```

- [ ] **Step 3 : Commit**

```bash
git add tests/Unit/Google/PushEventJobTest.php
git commit -m "test(google): cover PushEventJob confirm/delete/retry/edge cases"
```

---

## Task 16 : `Http\AdminGoogleController` — REST OAuth start + callback + status + disconnect

**Files:**
- Create: `src/Http/AdminGoogleController.php`
- Create: `tests/Integration/AdminGoogleControllerTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class AdminGoogleControllerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $httpStub = [];

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_google_accounts");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");

        update_option('tb_decision_secret', str_repeat('a', 64), false);
        update_option('tb_google_client_id', 'cid');
        update_option('tb_google_client_secret', 'csecret');

        // Stub HTTP — intercept wp_remote_post to oauth2.googleapis.com/token.
        $this->httpStub = [];
        add_filter('pre_http_request', [$this, 'interceptHttp'], 10, 3);

        wp_set_current_user(1);
        $user = wp_get_current_user();
        $user->add_cap('trinity_booking_manage');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptHttp'], 10);
    }

    public function interceptHttp(mixed $preempt, array $args, string $url): array
    {
        $this->httpStub[] = compact('args', 'url');
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => (string) wp_json_encode([
                    'access_token'  => 'access-XYZ',
                    'refresh_token' => 'refresh-XYZ',
                    'expires_in'    => 3600,
                    'scope'         => 'https://www.googleapis.com/auth/calendar.events',
                    'token_type'    => 'Bearer',
                ]),
                'headers'  => [],
                'cookies'  => [],
            ];
        }
        return ['response' => ['code' => 500, 'message' => 'KO'], 'body' => '', 'headers' => [], 'cookies' => []];
    }

    public function test_start_returns_auth_url_with_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('POST', '/trinity-booking/v1/admin/google/oauth/start');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('auth_url', $data);
        self::assertStringContainsString('accounts.google.com', (string) $data['auth_url']);
        self::assertStringContainsString('state=', (string) $data['auth_url']);
    }

    public function test_callback_exchanges_code_and_persists_account(): void
    {
        do_action('rest_api_init');
        global $wpdb;

        // Issue a state token via OAuthState.
        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => $state]);

        $res = rest_do_request($req);
        // 302 redirect or HTML response — we tolerate either, but no error.
        self::assertNotSame(403, $res->get_status());

        $repo = new GoogleAccountRepository($wpdb);
        $acct = $repo->findSingle();
        self::assertNotNull($acct);
        // Refresh token must be encrypted (not raw).
        self::assertNotSame('refresh-XYZ', $acct->refreshTokenEnc());
    }

    public function test_callback_rejects_invalid_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => 'garbage']);
        $res = rest_do_request($req);
        self::assertSame(403, $res->get_status());
    }

    public function test_status_returns_connected_after_callback(): void
    {
        do_action('rest_api_init');

        // First, connect via callback.
        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/status');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        $data = $res->get_data();
        self::assertTrue($data['connected']);
        self::assertSame('primary', $data['calendar_id']);
    }

    public function test_disconnect_removes_account(): void
    {
        do_action('rest_api_init');

        $state = (new \Trinity\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/trinity-booking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('POST', '/trinity-booking/v1/admin/google/disconnect');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());

        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);
        self::assertNull($repo->findSingle());
    }
}
```

- [ ] **Step 2 : Lancer → rouge ou skipped**

- [ ] **Step 3 : Créer `src/Http/AdminGoogleController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\Encryption;
use Trinity\Booking\Google\Exceptions\OAuthFailure;
use Trinity\Booking\Google\OAuthClient;
use Trinity\Booking\Google\OAuthState;
use Trinity\Booking\Persistence\GoogleAccountRepository;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminGoogleController
{
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly OAuthClient $oauthClient,
        private readonly OAuthState $state,
        private readonly Encryption $encryption,
    ) {
    }

    public function registerRoutes(): void
    {
        $ns = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/google/oauth/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'start'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/admin/google/oauth/callback', [
            'methods'             => 'GET',
            'callback'            => [$this, 'callback'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route($ns, '/admin/google/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => [$this, 'canManage'],
        ]);
        register_rest_route($ns, '/admin/google/disconnect', [
            'methods'             => 'POST',
            'callback'            => [$this, 'disconnect'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can(Capabilities::MANAGE);
    }

    public function start(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return new WP_Error('not_logged_in', 'Not logged in', ['status' => 401]);
        }
        $state = $this->state->issue($userId);
        $url   = $this->oauthClient->authUrl($state);
        return new WP_REST_Response(['auth_url' => $url], 200);
    }

    public function callback(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $code  = (string) $req->get_param('code');
        $state = (string) $req->get_param('state');

        if ($code === '' || $this->state->verify($state) === null) {
            return new WP_Error('invalid_state', 'Invalid or expired OAuth state.', ['status' => 403]);
        }

        try {
            $tokens = $this->oauthClient->exchangeCode($code);
        } catch (OAuthFailure $e) {
            return new WP_Error('oauth_failed', $e->getMessage(), ['status' => 502]);
        }

        if (!isset($tokens['refresh_token'])) {
            return new WP_Error(
                'missing_refresh_token',
                'Google did not return a refresh token. Revoke access at myaccount.google.com and retry with prompt=consent.',
                ['status' => 502]
            );
        }

        $existing = $this->accounts->findSingle();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . (int) $tokens['expires_in'] . ' seconds');

        $refreshEnc = $this->encryption->encrypt((string) $tokens['refresh_token']);
        $accessEnc  = $this->encryption->encrypt((string) $tokens['access_token']);

        if ($existing === null) {
            $account = GoogleAccount::connect(
                label: 'Commercial Trinity',
                calendarId: 'primary',
                refreshTokenEnc: $refreshEnc,
                accessTokenEnc: $accessEnc,
                expiresAt: $expiresAt,
            );
        } else {
            // Re-connect: rotate tokens on existing row.
            $account = $existing;
            $account->rotateAccessToken($accessEnc, $expiresAt);
            // Replace refresh token via reflection-free path: we recreate.
            $account = GoogleAccount::connect(
                label: $existing->label(),
                calendarId: $existing->calendarId(),
                refreshTokenEnc: $refreshEnc,
                accessTokenEnc: $accessEnc,
                expiresAt: $expiresAt,
            );
            if ($existing->id() !== null) {
                $account->assignId($existing->id());
            }
        }

        $this->accounts->save($account);

        // Redirect to admin SPA Google page.
        $redirect = admin_url('admin.php?page=trinity-booking#/google?connected=1');
        return new WP_REST_Response(null, 302, ['Location' => $redirect]);
    }

    public function status(): WP_REST_Response
    {
        $acct = $this->accounts->findSingle();
        if ($acct === null) {
            return new WP_REST_Response(['connected' => false], 200);
        }
        return new WP_REST_Response([
            'connected'   => true,
            'calendar_id' => $acct->calendarId(),
            'label'       => $acct->label(),
            'expires_at'  => $acct->expiresAt()->format(\DateTimeInterface::ATOM),
            'connected_since' => $acct->createdAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }

    public function disconnect(): WP_REST_Response
    {
        $acct = $this->accounts->findSingle();
        if ($acct !== null && $acct->id() !== null) {
            $this->accounts->delete($acct->id());
        }
        return new WP_REST_Response(['disconnected' => true], 200);
    }
}
```

- [ ] **Step 4 : Lancer → rouge (manque le wire-up dans RestRouter)**

- [ ] **Step 5 : Modifier `src/Http/RestRouter.php` pour enregistrer le controller**

Localiser la méthode `registerRoutes()`, juste avant la dernière ligne `(new AdminBookingController(...))->registerRoutes();`, ajouter :

```php
$accounts   = new \Trinity\Booking\Persistence\GoogleAccountRepository($wpdb);
$keyResolver = new \Trinity\Booking\Google\EncryptionKeyResolver();
$encryption  = new \Trinity\Booking\Google\Encryption($keyResolver->resolve());
$state       = new \Trinity\Booking\Google\OAuthState((string) get_option('tb_decision_secret'));
$oauthClient = new \Trinity\Booking\Google\OAuthClient(
    clientId: (string) get_option('tb_google_client_id', ''),
    clientSecret: (string) get_option('tb_google_client_secret', ''),
    redirectUri: rest_url(\Trinity\Booking\Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
);
(new AdminGoogleController($accounts, $oauthClient, $state, $encryption))->registerRoutes();
```

- [ ] **Step 6 : Lancer → vert ou skipped**

```bash
composer test:integration -- --filter AdminGoogleControllerTest
```

- [ ] **Step 7 : Commit**

```bash
git add src/Http/AdminGoogleController.php src/Http/RestRouter.php tests/Integration/AdminGoogleControllerTest.php
git commit -m "feat(http): AdminGoogleController for OAuth start/callback/status/disconnect"
```

---

## Task 17 : `Persistence\SyncLogRepository` wiring + `AdminSyncLogController`

**Files:**
- Create: `src/Http/AdminSyncLogController.php`
- Create: `tests/Integration/AdminSyncLogControllerTest.php`
- Modify: `src/Http/RestRouter.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use Trinity\Booking\Persistence\SyncLogRepository;

final class AdminSyncLogControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_sync_log");

        wp_set_current_user(1);
        wp_get_current_user()->add_cap('trinity_booking_manage');
    }

    public function test_list_returns_paginated_log(): void
    {
        do_action('rest_api_init');
        global $wpdb;
        $repo = new SyncLogRepository($wpdb);
        for ($i = 1; $i <= 4; $i++) {
            $repo->append('info', 'wp_to_g', 'booking', $i, 'evt_' . $i, 'create', 'ok', [], null);
        }

        $req = new WP_REST_Request('GET', '/trinity-booking/v1/admin/sync-log');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $req->set_query_params(['per_page' => 2, 'page' => 1]);
        $res = rest_do_request($req);
        $data = $res->get_data();

        self::assertSame(4, $data['total']);
        self::assertCount(2, $data['items']);
    }
}
```

- [ ] **Step 2 : Créer `src/Http/AdminSyncLogController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Persistence\SyncLogRepository;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminSyncLogController
{
    public function __construct(private readonly SyncLogRepository $log)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Plugin::REST_NAMESPACE, '/admin/sync-log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list'],
            'permission_callback' => fn () => current_user_can(Capabilities::MANAGE),
        ]);
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        $page    = max(1, (int) $req->get_param('page'));
        $perPage = min(200, max(1, (int) ($req->get_param('per_page') ?: 50)));
        $filters = [];
        foreach (['level', 'direction', 'status'] as $f) {
            $v = $req->get_param($f);
            if (is_string($v) && $v !== '') {
                $filters[$f] = $v;
            }
        }
        if ((int) $req->get_param('entity_id') > 0) {
            $filters['entity_id'] = (int) $req->get_param('entity_id');
        }
        return new WP_REST_Response($this->log->paginate($filters, $page, $perPage), 200);
    }
}
```

- [ ] **Step 3 : Wire-up dans `RestRouter.php`**

À la fin de `registerRoutes()`, ajouter :

```php
$syncLog = new \Trinity\Booking\Persistence\SyncLogRepository($wpdb);
(new AdminSyncLogController($syncLog))->registerRoutes();
```

- [ ] **Step 4 : Lancer → vert ou skipped**

```bash
composer test:integration -- --filter AdminSyncLogControllerTest
```

- [ ] **Step 5 : Commit**

```bash
git add src/Http/AdminSyncLogController.php src/Http/RestRouter.php tests/Integration/AdminSyncLogControllerTest.php
git commit -m "feat(http): AdminSyncLogController GET /admin/sync-log"
```

---

## Task 18 : `Google\SyncLogPurger` — daily cron

**Files:**
- Create: `src/Google/SyncLogPurger.php`
- Create: `tests/Unit/Google/SyncLogPurgerTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Google\SyncLogPurger;

final class SyncLogPurgerTest extends TestCase
{
    public function test_purge_called_with_30_day_cutoff(): void
    {
        $captured = null;
        $purger = new SyncLogPurger(
            purge: function (DateTimeImmutable $cutoff) use (&$captured): int {
                $captured = $cutoff;
                return 7;
            }
        );

        $now = new DateTimeImmutable('2026-06-01T12:00:00Z');
        $deleted = $purger->run($now);

        self::assertSame(7, $deleted);
        self::assertSame('2026-05-02T12:00:00+00:00', $captured?->format(\DateTimeInterface::ATOM));
    }
}
```

- [ ] **Step 2 : Créer `src/Google/SyncLogPurger.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class SyncLogPurger
{
    public const HOOK = 'tb_purge_sync_log';
    public const RETENTION_DAYS = 30;

    /** @var Closure(DateTimeImmutable): int */
    private Closure $purge;

    /**
     * @param Closure(DateTimeImmutable): int $purge
     */
    public function __construct(Closure $purge)
    {
        $this->purge = $purge;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'runOnCron']);
    }

    public function runOnCron(): void
    {
        $this->run(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function run(DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-' . self::RETENTION_DAYS . ' days');
        return ($this->purge)($cutoff);
    }
}
```

- [ ] **Step 3 : Lancer → vert**

- [ ] **Step 4 : Schedule le cron dans `Activator.php`**

Modifier la méthode `activate()` pour ajouter, après le cron des reminders :

```php
if (!wp_next_scheduled(\Trinity\Booking\Google\SyncLogPurger::HOOK)) {
    wp_schedule_event(self::tomorrowAt3SiteTz(), 'daily', \Trinity\Booking\Google\SyncLogPurger::HOOK);
}
```

Et ajouter la méthode :

```php
private static function tomorrowAt3SiteTz(): int
{
    $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
    return (new \DateTimeImmutable('tomorrow 03:00', $tz))->getTimestamp();
}
```

Modifier `Deactivator.php` pour unschedule :

```php
public static function deactivate(): void
{
    wp_clear_scheduled_hook(\Trinity\Booking\Notifications\ReminderScheduler::HOOK);
    wp_clear_scheduled_hook(\Trinity\Booking\Google\SyncLogPurger::HOOK);
}
```

- [ ] **Step 5 : Commit**

```bash
git add src/Google/SyncLogPurger.php src/Activator.php src/Deactivator.php tests/Unit/Google/SyncLogPurgerTest.php
git commit -m "feat(google): daily sync-log purger (30-day retention)"
```

---

## Task 19 : Plugin.php wire-up — Google services, PushScheduler, PushEventJob

**Files:**
- Modify: `src/Plugin.php`

C'est l'orchestration. On ajoute les nouveaux services et on enregistre le handler Action Scheduler.

- [ ] **Step 1 : Modifier `src/Plugin.php`**

Dans `register()`, après les blocs Notifications/Admin existants, ajouter :

```php
// ----- Google sync (Plan 3) -----
$accounts    = new Persistence\GoogleAccountRepository($wpdb);
$syncLogRepo = new Persistence\SyncLogRepository($wpdb);
$keyResolver = new Google\EncryptionKeyResolver();
$encryption  = new Google\Encryption($keyResolver->resolve());

$pendingColor   = (string) get_option('tb_gcal_color_pending', '6');
$confirmedColor = (string) get_option('tb_gcal_color_confirmed', '10');
$formatter      = new Google\EventFormatter($pendingColor, $confirmedColor);

(new Google\PushScheduler())->register();

(new Google\SyncLogPurger(
    purge: fn (\DateTimeImmutable $cutoff): int => $syncLogRepo->purgeOlderThan($cutoff),
))->register();

// Register Action Scheduler handler.
add_action(Google\PushScheduler::HOOK, function (int $bookingId, string $action) use (
    $bookings,
    $services,
    $accounts,
    $syncLogRepo,
    $encryption,
    $formatter,
    $keyResolver
): void {
    $booking = $bookings->findById($bookingId);
    if ($booking === null) {
        return;
    }
    $service = $services->findById($booking->serviceId());
    if ($service === null) {
        return;
    }

    $account = $accounts->findSingle();
    if ($account === null) {
        $syncLogRepo->append(
            level: 'warn', direction: 'wp_to_g', entity: 'booking',
            entityId: $bookingId, googleEventId: null, action: $action,
            status: 'failed', payload: [], errorMessage: 'No Google account connected',
        );
        return;
    }

    $gateway = (new Google\GoogleClientBuilder($encryption, $accounts))->buildGateway($account);

    $job = new Google\PushEventJob(
        findBooking: fn () => $booking,
        findAccount: fn () => $account,
        persistBooking: fn (Domain\Booking $b) => $bookings->save($b),
        gateway: $gateway,
        formatter: $formatter,
        service: $service,
        log: function (array $entry) use ($syncLogRepo): void {
            $syncLogRepo->append(
                level: (string) $entry['level'],
                direction: (string) $entry['direction'],
                entity: (string) $entry['entity'],
                entityId: (int) $entry['entity_id'],
                googleEventId: $entry['google_event_id'] !== null ? (string) $entry['google_event_id'] : null,
                action: (string) $entry['action'],
                status: (string) $entry['status'],
                payload: [],
                errorMessage: $entry['error_message'] !== null ? (string) $entry['error_message'] : null,
            );
        },
    );

    $job->handle($bookingId, $action);
}, 10, 2);

// Admin notice if encryption key falls back to option.
if ($keyResolver->usingFallback()) {
    add_action('admin_notices', function (): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Trinity Booking :</strong> définissez <code>TRINITY_BOOKING_ENC_KEY</code> dans <code>wp-config.php</code> pour chiffrer les tokens Google avec une clé hors base.</p></div>';
    });
}
```

- [ ] **Step 2 : Créer `src/Google/GoogleClientBuilder.php`** (nouvelle classe utilitaire qui construit un client Google + gère le refresh + persiste les tokens rotés)

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client as GoogleClient;
use Trinity\Booking\Domain\GoogleAccount;
use Trinity\Booking\Google\Exceptions\OAuthFailure;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class GoogleClientBuilder
{
    public function __construct(
        private readonly Encryption $encryption,
        private readonly GoogleAccountRepository $accounts,
    ) {
    }

    public function buildGateway(GoogleAccount $account): CalendarGateway
    {
        $client = new GoogleClient();
        $client->setClientId((string) get_option('tb_google_client_id', ''));
        $client->setClientSecret((string) get_option('tb_google_client_secret', ''));
        $client->addScope(OAuthClient::SCOPE);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now->modify('+30 seconds'))) {
            $this->refresh($account, $client);
        } else {
            $client->setAccessToken([
                'access_token' => $this->encryption->decrypt($account->accessTokenEnc()),
                'expires_in'   => max(0, $account->expiresAt()->getTimestamp() - $now->getTimestamp()),
                'created'      => $now->getTimestamp(),
            ]);
        }

        return new GoogleApiCalendarGateway($client);
    }

    private function refresh(GoogleAccount $account, GoogleClient $client): void
    {
        $oauth = new OAuthClient(
            clientId: (string) get_option('tb_google_client_id', ''),
            clientSecret: (string) get_option('tb_google_client_secret', ''),
            redirectUri: '',
        );
        try {
            $refresh = $this->encryption->decrypt($account->refreshTokenEnc());
            $tokens = $oauth->refreshAccessToken($refresh);
        } catch (\Throwable $e) {
            throw new OAuthFailure('Refresh failed: ' . $e->getMessage(), 0, $e);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . (int) $tokens['expires_in'] . ' seconds');
        $account->rotateAccessToken($this->encryption->encrypt((string) $tokens['access_token']), $expiresAt);
        $this->accounts->save($account);

        $client->setAccessToken([
            'access_token' => (string) $tokens['access_token'],
            'expires_in'   => (int) $tokens['expires_in'],
            'created'      => $now->getTimestamp(),
        ]);
    }
}
```

- [ ] **Step 3 : Vérifier qu'on n'a pas cassé la suite**

```bash
composer test
composer stan
composer cs
```

Si PHPStan râle sur des types Google\Client (parce que la lib n'est pas autoloadée dans le contexte stan), ajouter au `phpstan.neon` :

```yaml
parameters:
    ignoreErrors:
        - '#Class Google\\Client not found#'
        - '#Class Google\\Service\\Calendar not found#'
        - '#Class Google\\Service\\Calendar\\Event not found#'
```

Note : normalement, `composer dump-autoload` règle le problème — n'ajouter les ignores que si nécessaire.

- [ ] **Step 4 : Commit**

```bash
git add src/Plugin.php src/Google/GoogleClientBuilder.php phpstan.neon
git commit -m "feat(plugin): wire Google sync pipeline (Action Scheduler handler + client builder)"
```

---

## Task 20 : Admin SPA — `GooglePage` (connect/disconnect/status)

**Files:**
- Create: `src/Admin/react-app/src/GooglePage.jsx`
- Modify: `src/Admin/react-app/src/App.jsx` (ajout navigation)
- Modify: `src/Admin/react-app/src/api.js` (nouveaux endpoints)

- [ ] **Step 1 : Étendre `api.js`**

Ajouter les fonctions :

```js
export async function fetchGoogleStatus() {
    return wp.apiFetch({ path: 'trinity-booking/v1/admin/google/status' });
}

export async function startGoogleOAuth() {
    return wp.apiFetch({
        path: 'trinity-booking/v1/admin/google/oauth/start',
        method: 'POST',
    });
}

export async function disconnectGoogle() {
    return wp.apiFetch({
        path: 'trinity-booking/v1/admin/google/disconnect',
        method: 'POST',
    });
}

export async function fetchSyncLog({ page = 1, perPage = 50, level, status } = {}) {
    const params = new URLSearchParams({ page, per_page: perPage });
    if (level) params.set('level', level);
    if (status) params.set('status', status);
    return wp.apiFetch({ path: `trinity-booking/v1/admin/sync-log?${params}` });
}
```

- [ ] **Step 2 : Créer `GooglePage.jsx`**

```jsx
import { useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { fetchGoogleStatus, startGoogleOAuth, disconnectGoogle } from './api';

export default function GooglePage() {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const reload = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await fetchGoogleStatus();
            setStatus(data);
        } catch (e) {
            setError(e.message ?? String(e));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        reload();
    }, []);

    const connect = async () => {
        try {
            const { auth_url } = await startGoogleOAuth();
            window.location.href = auth_url;
        } catch (e) {
            setError(e.message ?? String(e));
        }
    };

    const disconnect = async () => {
        if (!window.confirm(__('Vraiment déconnecter ce compte ?', 'trinity-booking'))) return;
        try {
            await disconnectGoogle();
            await reload();
        } catch (e) {
            setError(e.message ?? String(e));
        }
    };

    if (loading) return <Spinner />;

    return (
        <Card>
            <CardHeader>
                <h2>{__('Google Calendar', 'trinity-booking')}</h2>
            </CardHeader>
            <CardBody>
                {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
                {status?.connected ? (
                    <>
                        <p>
                            <strong>{__('Connecté', 'trinity-booking')} ✓</strong>
                            <br />
                            {__('Calendrier :', 'trinity-booking')} <code>{status.calendar_id}</code>
                            <br />
                            {__('Token expire :', 'trinity-booking')} {new Date(status.expires_at).toLocaleString()}
                        </p>
                        <Button variant="secondary" isDestructive onClick={disconnect}>
                            {__('Déconnecter', 'trinity-booking')}
                        </Button>
                    </>
                ) : (
                    <>
                        <p>{__('Aucun calendrier Google connecté.', 'trinity-booking')}</p>
                        <Button variant="primary" onClick={connect}>
                            {__('Connecter mon Google Calendar', 'trinity-booking')}
                        </Button>
                    </>
                )}
            </CardBody>
        </Card>
    );
}
```

- [ ] **Step 3 : Modifier `App.jsx` pour ajouter une nav simple**

Garder l'existant pour BookingsPage, et router selon le hash :

```jsx
import { useState, useEffect } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';
import GooglePage from './GooglePage';
import SyncLogPage from './SyncLogPage';

export default function App() {
    return (
        <TabPanel
            className="tb-tabs"
            tabs={[
                { name: 'bookings', title: __('Réservations', 'trinity-booking') },
                { name: 'google',   title: __('Google', 'trinity-booking') },
                { name: 'log',      title: __('Journal', 'trinity-booking') },
            ]}
            initialTabName={location.hash.replace('#/', '') || 'bookings'}
            onSelect={(name) => { history.replaceState(null, '', `#/${name}`); }}
        >
            {(tab) => {
                if (tab.name === 'google') return <GooglePage />;
                if (tab.name === 'log')    return <SyncLogPage />;
                return <BookingsPage />;
            }}
        </TabPanel>
    );
}
```

- [ ] **Step 4 : Build**

```bash
npm run build
```
Attendu : 0 erreur, `assets/dist/admin.js` régénéré.

- [ ] **Step 5 : Commit**

```bash
git add src/Admin/react-app/src/GooglePage.jsx src/Admin/react-app/src/App.jsx src/Admin/react-app/src/api.js
git commit -m "feat(admin-spa): GooglePage with connect/disconnect flow + tab nav"
```

---

## Task 21 : Admin SPA — `SyncLogPage`

**Files:**
- Create: `src/Admin/react-app/src/SyncLogPage.jsx`

- [ ] **Step 1 : Créer `SyncLogPage.jsx`**

```jsx
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { fetchSyncLog } from './api';

const STATUS_FILTERS = [
    { label: '— Tous —', value: '' },
    { label: 'OK', value: 'ok' },
    { label: 'Retry', value: 'retry' },
    { label: 'Failed', value: 'failed' },
];

export default function SyncLogPage() {
    const [items, setItems] = useState([]);
    const [total, setTotal] = useState(0);
    const [page, setPage]   = useState(1);
    const [status, setStatus] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const perPage = 50;

    const load = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await fetchSyncLog({ page, perPage, status });
            setItems(data.items);
            setTotal(data.total);
        } catch (e) {
            setError(e.message ?? String(e));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { load(); }, [page, status]);

    if (loading && items.length === 0) return <Spinner />;

    const pages = Math.max(1, Math.ceil(total / perPage));

    return (
        <div>
            <h2>{__('Journal de synchronisation', 'trinity-booking')}</h2>
            {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
            <SelectControl
                label={__('Statut', 'trinity-booking')}
                value={status}
                options={STATUS_FILTERS}
                onChange={(v) => { setStatus(v); setPage(1); }}
            />
            <table className="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>{__('Date', 'trinity-booking')}</th>
                        <th>{__('Direction', 'trinity-booking')}</th>
                        <th>{__('Entité', 'trinity-booking')}</th>
                        <th>{__('Action', 'trinity-booking')}</th>
                        <th>{__('Statut', 'trinity-booking')}</th>
                        <th>{__('Erreur', 'trinity-booking')}</th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((it) => (
                        <tr key={it.id}>
                            <td>{it.ts}</td>
                            <td>{it.direction}</td>
                            <td>{it.entity}#{it.entity_id ?? '–'}</td>
                            <td>{it.action}</td>
                            <td>{it.status}</td>
                            <td>{it.error_message ?? ''}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="tb-pagination">
                <Button disabled={page <= 1}     onClick={() => setPage(page - 1)}>‹</Button>
                <span>{page} / {pages}</span>
                <Button disabled={page >= pages} onClick={() => setPage(page + 1)}>›</Button>
            </div>
        </div>
    );
}
```

- [ ] **Step 2 : Build**

```bash
npm run build
```

- [ ] **Step 3 : Commit**

```bash
git add src/Admin/react-app/src/SyncLogPage.jsx
git commit -m "feat(admin-spa): SyncLogPage with filters + pagination"
```

---

## Task 22 : `Cli\DoctorCommand` — `wp trinity-booking doctor`

**Files:**
- Create: `src/Cli/DoctorCommand.php`
- Modify: `src/Plugin.php` (registration via `WP_CLI::add_command`)

- [ ] **Step 1 : Créer `src/Cli/DoctorCommand.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Google\Encryption;
use Trinity\Booking\Google\GoogleClientBuilder;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class DoctorCommand
{
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly Encryption $encryption,
        private readonly GoogleClientBuilder $clientBuilder,
    ) {
    }

    /**
     * Run diagnostics: OAuth state, token refresh, calendar reachability.
     *
     * ## EXAMPLES
     *
     *     wp trinity-booking doctor
     *
     * @when after_wp_load
     */
    public function __invoke(array $args, array $assoc): void
    {
        \WP_CLI::log('🩺 trinity-booking doctor');
        \WP_CLI::log('—————————————————');

        $account = $this->accounts->findSingle();
        if ($account === null) {
            \WP_CLI::warning('No Google account connected. Run OAuth flow from admin.');
            return;
        }

        \WP_CLI::success(sprintf(
            'Account: %s (calendar=%s, expires=%s)',
            $account->label(),
            $account->calendarId(),
            $account->expiresAt()->format(\DateTimeInterface::ATOM),
        ));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now)) {
            \WP_CLI::log('Access token expired — attempting refresh…');
        }

        try {
            $gateway = $this->clientBuilder->buildGateway($account);
        } catch (\Throwable $e) {
            \WP_CLI::error('Failed to build Google client: ' . $e->getMessage());
            return;
        }
        \WP_CLI::success('Google client built (token refresh OK if needed).');

        // Smoke test: insert + delete a probe event.
        $probe = [
            'summary'     => '[trinity-booking doctor probe — safe to delete]',
            'description' => 'Self-test event. Will be deleted immediately.',
            'start'       => ['dateTime' => $now->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'timeZone' => 'UTC'],
            'end'         => ['dateTime' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'), 'timeZone' => 'UTC'],
        ];
        try {
            $ref = $gateway->insertEvent($account->calendarId(), $probe);
            \WP_CLI::success('Inserted probe event ' . $ref['id']);
            $gateway->deleteEvent($account->calendarId(), $ref['id']);
            \WP_CLI::success('Deleted probe event ' . $ref['id']);
        } catch (\Throwable $e) {
            \WP_CLI::error('Probe failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2 : Enregistrer dans `Plugin::register()`**

À la fin de `register()`, ajouter :

```php
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('trinity-booking doctor', new Cli\DoctorCommand(
        $accounts,
        $encryption,
        new Google\GoogleClientBuilder($encryption, $accounts),
    ));
}
```

- [ ] **Step 3 : Tester manuellement**

(Nécessite un WP local + un compte Google connecté.)

```bash
wp trinity-booking doctor
```
Attendu : 3 lignes ✓ (account, client, probe insert+delete).

- [ ] **Step 4 : Commit**

```bash
git add src/Cli/DoctorCommand.php src/Plugin.php
git commit -m "feat(cli): wp trinity-booking doctor diagnoses OAuth + calendar reachability"
```

---

## Task 23 : Settings — capture Google client_id / client_secret

**Files:**
- Modify: `src/Admin/react-app/src/GooglePage.jsx`
- Create: `src/Http/AdminGoogleSettingsController.php`
- Modify: `src/Http/RestRouter.php`

L'admin doit pouvoir saisir le `client_id` et le `client_secret` (créés sur console.cloud.google.com) sans toucher au wp-config. On stocke en options WP.

- [ ] **Step 1 : Créer `AdminGoogleSettingsController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class AdminGoogleSettingsController
{
    public function registerRoutes(): void
    {
        $cap = fn () => current_user_can(Capabilities::MANAGE);

        register_rest_route(Plugin::REST_NAMESPACE, '/admin/google/settings', [
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
        $secret = (string) get_option('tb_google_client_secret', '');
        return new WP_REST_Response([
            'client_id'         => (string) get_option('tb_google_client_id', ''),
            'has_client_secret' => $secret !== '',
            'redirect_uri'      => rest_url(Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        ], 200);
    }

    public function write(WP_REST_Request $req): WP_REST_Response
    {
        $clientId = sanitize_text_field((string) $req->get_param('client_id'));
        $secret   = (string) $req->get_param('client_secret');
        update_option('tb_google_client_id', $clientId, false);
        if ($secret !== '') {
            update_option('tb_google_client_secret', $secret, false);
        }
        return new WP_REST_Response(['saved' => true], 200);
    }
}
```

- [ ] **Step 2 : Wire-up dans `RestRouter.php`**

Ajouter à la fin de `registerRoutes()` :

```php
(new AdminGoogleSettingsController())->registerRoutes();
```

- [ ] **Step 3 : Étendre `GooglePage.jsx` avec un formulaire de settings**

Au-dessus du Card "connected/connect", ajouter un Card "settings" :

```jsx
import { TextControl } from '@wordpress/components';

// ... dans le composant, ajouter :
const [settings, setSettings] = useState(null);
const [secret, setSecret] = useState('');

const loadSettings = async () => {
    const s = await wp.apiFetch({ path: 'trinity-booking/v1/admin/google/settings' });
    setSettings(s);
};

useEffect(() => { loadSettings(); }, []);

const saveSettings = async () => {
    await wp.apiFetch({
        path: 'trinity-booking/v1/admin/google/settings',
        method: 'POST',
        data: { client_id: settings.client_id, client_secret: secret },
    });
    setSecret('');
    loadSettings();
};

// ... dans le render, avant le Card de connexion :
{settings && (
    <Card>
        <CardHeader><h2>{__('Configuration OAuth', 'trinity-booking')}</h2></CardHeader>
        <CardBody>
            <p>
                <strong>{__('URI de redirection à saisir dans Google Cloud Console :', 'trinity-booking')}</strong>
                <br /><code>{settings.redirect_uri}</code>
            </p>
            <TextControl
                label="Client ID"
                value={settings.client_id}
                onChange={(v) => setSettings({ ...settings, client_id: v })}
            />
            <TextControl
                label={settings.has_client_secret ? __('Client Secret (déjà défini — saisir pour remplacer)', 'trinity-booking') : 'Client Secret'}
                type="password"
                value={secret}
                onChange={setSecret}
            />
            <Button variant="primary" onClick={saveSettings}>{__('Enregistrer', 'trinity-booking')}</Button>
        </CardBody>
    </Card>
)}
```

- [ ] **Step 4 : Build**

```bash
npm run build
```

- [ ] **Step 5 : Commit**

```bash
git add src/Http/AdminGoogleSettingsController.php src/Http/RestRouter.php src/Admin/react-app/src/GooglePage.jsx
git commit -m "feat(admin): capture Google OAuth client_id/client_secret via SPA"
```

---

## Task 24 : Documentation README — Google OAuth setup

**Files:**
- Modify: `README.md`

- [ ] **Step 1 : Ajouter une section "Google Calendar setup"**

Localiser la section "Quickstart" ou "Installation" et insérer après :

````markdown
## Google Calendar setup (Plan 3)

1. **Créer un projet sur Google Cloud Console** : https://console.cloud.google.com → APIs & Services → Library → activer **Google Calendar API**.
2. **OAuth consent screen** : configurer en mode "External", ajouter votre adresse e-mail comme test user (V1, mono-commercial).
3. **OAuth 2.0 Client ID** :
   - Type : Web application
   - Authorized redirect URI : copier l'URI affichée dans **Trinity Booking → Google → Configuration OAuth** (typiquement `https://votresite.fr/wp-json/trinity-booking/v1/admin/google/oauth/callback`).
4. **Coller** le `Client ID` et le `Client secret` dans le formulaire admin du plugin et **Enregistrer**.
5. Cliquer **Connecter mon Google Calendar** → autoriser → retour automatique sur la page admin avec `?connected=1`.
6. (Recommandé) Définir dans `wp-config.php` :

   ```php
   define('TRINITY_BOOKING_ENC_KEY', '<64-char hex string, ex: bin2hex(random_bytes(32))>');
   ```

   Sinon une clé fallback est générée et stockée en option (warning visible côté admin).

## CLI diagnostics

```bash
wp trinity-booking doctor
```

Vérifie : compte connecté, refresh token valide, accès Calendar API (insère puis supprime un event de test).
````

- [ ] **Step 2 : Mettre à jour la section "Statut"**

```markdown
## Statut
- ✅ Plan 1 : Bootstrap + fondations publiques
- ✅ Plan 2 : Notifications e-mail + validation admin
- 🚧 Plan 3 : Google OAuth + push WP → GCal
- ⏸️ Plan 4 : Webhook Google + pull GCal → WP
- ⏸️ Plan 5 : Templates editor + RGPD + i18n + packaging
```

- [ ] **Step 3 : Commit**

```bash
git add README.md
git commit -m "docs: document Google OAuth setup + doctor command"
```

---

## Task 25 : Vérification finale + self-review

**Files:**
- Read: spec sections 2 (sync), 5 (`wp_tb_google_accounts`/`wp_tb_sync_log`), 6.1 (jobs push), 7 (REST), 9 (chiffrement), 12 (observabilité)

- [ ] **Step 1 : Suite complète**

```bash
composer test
composer test:integration
composer stan
composer cs
npm run lint:js
npm run build
```

Tout vert (skipped propre OK si wp-phpunit absent).

- [ ] **Step 2 : Self-review couverture Plan 3**

Cocher chaque exigence :

- [ ] Composer deps `google/apiclient` + `action-scheduler` (Task 1) ✓
- [ ] Encryption sodium + key resolver wp-config|option (Tasks 3, 4) ✓
- [ ] `GoogleAccount` domain + repository (Tasks 5, 6) ✓
- [ ] OAuth state HMAC (Task 7) ✓
- [ ] OAuth code exchange + refresh (Task 8) ✓
- [ ] `CalendarGateway` interface + SDK impl + fake (Tasks 9, 12) ✓
- [ ] `EventFormatter` (Task 10) ✓
- [ ] `SyncLogRepository` + purge cron (Tasks 11, 18) ✓
- [ ] `PushScheduler` enfile Action Scheduler (Task 13) ✓
- [ ] `PushEventJob` create/confirm/delete + idempotence + retry (Tasks 14, 15) ✓
- [ ] REST `/admin/google/oauth/start|callback|status|disconnect` (Task 16) ✓
- [ ] REST `/admin/sync-log` (Task 17) ✓
- [ ] React `GooglePage` + `SyncLogPage` + tab nav (Tasks 20, 21) ✓
- [ ] `wp trinity-booking doctor` (Task 22) ✓
- [ ] Settings OAuth client_id/secret (Task 23) ✓
- [ ] README documenté (Task 24) ✓

- [ ] **Step 3 : Test manuel bout-en-bout**

1. WP local + plugin activé + `composer install` + `npm install && npm run build`.
2. Aller dans **Trinity Booking → Google → Configuration OAuth**, saisir un `client_id` + `client_secret` valides.
3. Cliquer **Connecter** → autoriser → retour OK, statut "Connecté".
4. Créer un booking via `POST /bookings` → un event apparaît dans Google Calendar (couleur orange, `[À VALIDER]` préfixé).
5. Cliquer "Confirmer" dans l'e-mail admin → event devient vert, préfixe retiré.
6. Cliquer le lien d'annulation client → event supprimé.
7. Aller dans **Trinity Booking → Journal** → 3 lignes `ok`.
8. `wp trinity-booking doctor` → 3 ✓.

- [ ] **Step 4 : Mettre à jour la mémoire**

Mettre à jour `project_overview.md` (table des plans : Plan 3 → ✅ Terminé YYYY-MM-DD).

Mettre à jour `project_post_plan2_actions.md` ou créer `project_post_plan3_actions.md` avec :
- Plan 4 prêt à démarrer (webhook + pull).
- Action Scheduler maintenant en place, peut être réutilisé pour le pull.
- PHP-Scoper / Mozart toujours repoussé au Plan 5.

- [ ] **Step 5 : Commit final**

```bash
git add README.md
git commit -m "docs: mark Plan 3 complete"
```

---

## Definition of Done — Plan 3

- Tous tests unit + integration verts (skip propre si wp-phpunit non installée).
- PHPStan niveau 8 : 0 erreur (ignores possibles pour les classes Google\\* si autoload absent en CI).
- PHPCS : 0 erreur.
- `npm run build` produit `assets/dist/admin.{js,css,asset.php}` sans erreur ; `npm run lint:js` clean.
- Manuel : cycle complet booking → confirmation → annulation visible côté GCal et journalisé dans `wp_tb_sync_log`.
- `wp trinity-booking doctor` réussit sur un environnement connecté.
- README documente la procédure Google Cloud Console + la constante `TRINITY_BOOKING_ENC_KEY`.

---

## Quickstart (V1 après Plan 3)

```bash
composer install
npm install && npm run build
composer test && composer test:integration && composer stan
```

Activer le plugin, configurer Google OAuth depuis le menu admin, prendre un RDV de test, vérifier dans Google Calendar.

---

## Self-review (effectué par l'auteur du plan)

**Spec coverage :**

- §2 (sync bidirectionnelle, OAuth utilisateur, colorId 6/10) → Tasks 1, 10, 14 ✓ (push uniquement ; pull en Plan 4)
- §4 (`Google/` module, séparation Domain/Persistence) → Tasks 5, 6, 9, 10, 12, 13, 14, 18 ✓
- §5 (`wp_tb_google_accounts`, `wp_tb_sync_log`) → schéma déjà migré Plan 1, repositories Tasks 6, 11 ✓
- §6.1 (jobs `tb/create_gcal_event`, push job, idempotence) → renommé `tb/push_gcal_event` avec discriminator action, Tasks 13, 14 ✓
- §7 (REST `/admin/google/oauth/start`, `callback`, `/admin/sync-log`) → Tasks 16, 17, 23 ✓
- §9 (chiffrement OAuth via sodium + `TRINITY_BOOKING_ENC_KEY` fallback option avec warning) → Tasks 3, 4, 19 ✓
- §12 (observabilité : journal + diagnostics CLI) → Tasks 11, 17, 21, 22 ✓
- §15 (mitigation quotas Google : Action Scheduler avec backoff) → Tasks 13, 14 ✓ (3xx-4xx déterministes ne retentent pas)
- §15 (scoping) → repoussé au Plan 5, documenté en Préambule §12

**Placeholder scan :** aucune section TBD ; chaque step a son code complet ; signatures cohérentes (`CalendarGateway::insertEvent/patchEvent/deleteEvent`, `PushEventJob::handle($id, $action)`, `EventFormatter::format(Booking, Service)`).

**Type consistency :**

- `PushScheduler::HOOK = 'tb/push_gcal_event'` utilisé identiquement dans `PushScheduler` (enqueue), `Plugin::register()` (`add_action`).
- `OAuthClient::SCOPE` utilisé dans `OAuthClient::authUrl()` et `GoogleClientBuilder` (add_scope).
- Repository `findSingle()` (V1 mono-compte) appelé identiquement dans `AdminGoogleController::status/disconnect/callback`, `Plugin::register` action handler, `DoctorCommand`.
- `EventFormatter` ctor signature `__construct(string $pendingColorId, string $confirmedColorId)` utilisée identiquement dans tests et `Plugin::register`.
- `Encryption::__construct(string $key)` — 32 octets binaires (pas hex), résolu par `EncryptionKeyResolver::resolve()` qui retourne du binaire.
- `Booking::clearGoogleEvent()` ajoutée Task 14, utilisée Task 14 (`doDelete`).

**Hooks WP nommés :**

- `trinity_booking/booking_created` / `_confirmed` / `_rejected` / `_cancelled` → écoutés par `PushScheduler::register()` à priorité 20 (après `BookingNotifier` à priorité 10).
- `tb/push_gcal_event` → enregistré dans `Plugin::register()`, dispatché par Action Scheduler.
- `tb_purge_sync_log` → cron quotidien, géré par `SyncLogPurger::runOnCron()`.

Tous nommés identiquement aux Plans 1 et 2 (cohérence préfixes).
