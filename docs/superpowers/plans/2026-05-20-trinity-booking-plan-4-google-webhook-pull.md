# slashbooking — Plan 4 : Webhook Google + pull GCal → WP

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Synchroniser en continu Google Calendar → WordPress : un événement créé/modifié/supprimé directement dans GCal est répercuté en `BusyBlock` côté plugin en quelques secondes via le mécanisme **push notifications** Google (watch channel + webhook), avec un cron 15 min de fallback et un cron 6 jours pour renouveler le channel. Ferme la boucle de sync bidirectionnelle commencée Plan 3.

**Architecture:**

- **`Google/`** — trois nouveaux fichiers : `WatchChannelManager` (création / arrêt / renouvellement du watch via `events.watch`), `SyncEngine` (logique pull = `events.list` + `syncToken` incrémental + upsert/delete BusyBlock + détection reflection), `PullEventJob` (handler Action Scheduler `sb/google_pull`).
- **`Http/`** — un nouveau controller `GoogleWebhookController` (POST `/google/webhook`, public, vérif HMAC sur `X-Goog-Channel-Token`).
- **`Persistence/BusyBlockRepository`** — étendu : `upsertFromGoogle`, `deleteBySourceId`, `findBySourceId`. `GoogleAccountRepository::toRow()` complété (les colonnes `watch_resource_id`, `watch_token_secret`, `last_full_sync_at` n'étaient pas persistées — bug latent du Plan 3 à fixer).
- **`Domain/GoogleAccount`** — étendu : `attachWatch / clearWatch / verifyWatchToken / updateSyncToken / markFullSyncedAt`.
- **`Domain/BusyBlock`** — étendu : factory `fromGoogleEvent` + nouveau ctor pour upsert (avec `lastSyncedAt`).
- **`CalendarGateway`** — interface étendue avec `listEvents`, `watchChannel`, `stopChannel`. Impls réelle + fake mises à jour.
- **Cron** : `sb/watch_renew_check` (quotidien, recreate watch channel si expiration < 24h) + `sb/google_pull_all` (15 min, fallback no-op si watch fonctionne).
- **Outillage** : **upgrade PHPStan 1.x → 2.x** en tâche 1 (préreq tooling). Doctor étendu (watch status). SPA `GooglePage` montre statut watch + dernier sync.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, `google/apiclient` v2.x (`Calendar::events->list`, `events->watch`, `channels->stop`), Action Scheduler v3.x, PHPStan 2.x (upgrade), PHPUnit 10 + Brain Monkey.

**Spec source:** `docs/superpowers/specs/2026-05-19-slashbooking-design.md`, sections 5 (`wp_sb_busy_blocks`, `wp_sb_google_accounts`), 6.5 (sync entrante), 6.6 (watch renewal), 7 (REST `/google/webhook`), 9 (vérification `X-Goog-Channel-Token`), 12 (Diagnostics), 15 (mitigation webhook non-reçu = cron fallback).

---

## Préambule — Concepts clés pour l'ingénieur

Lire avant d'attaquer les tâches.

1. **Watch channel Google = push notifications HTTP.** On appelle `POST https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events/watch` avec un payload `{ id, type:"web_hook", address, token, expiration }`. Google retourne `{ resourceId, expiration }`. À chaque modif du calendrier, Google POST notre `address` avec headers `X-Goog-Resource-State` (`exists` ou `sync`), `X-Goog-Channel-Id`, `X-Goog-Channel-Token`, `X-Goog-Resource-Id`. **Le body est vide.** On répond `200` immédiatement et on enfile un job async. Expiration max = 7 jours → on renouvelle tous les 6 jours par cron.

2. **`syncToken` = curseur incrémental.** Premier appel à `events.list` sans `syncToken` → réponse paginée complète + `nextSyncToken` à la fin. On stocke ce token. Les appels suivants utilisent `syncToken` → Google renvoie seulement les diffs depuis l'appel précédent. **`410 Gone`** sur `events.list` = token invalide ou trop vieux : on doit reset `sync_token=NULL` et refaire un full sync.

3. **Pagination.** `events.list` paginé via `nextPageToken`. Un full sync peut nécessiter N pages — on boucle jusqu'à plus de `nextPageToken`. **Important :** `nextSyncToken` est renvoyé **uniquement sur la dernière page**, pas en milieu de pagination. Notre `SyncEngine` doit savoir : tant que `nextPageToken` est présent → on continue ; sinon on stocke le `nextSyncToken`.

4. **Réflexion (reflection) de notre propre push.** Quand on crée un event GCal via le push Plan 3, Google nous re-notifie via webhook. Notre `SyncEngine` doit ignorer ces events : on consulte `BookingRepository::findByGoogleEventId(string $id)`. Si trouvé → skip (notre booking maître). Sinon → c'est un event "externe" → upsert `BusyBlock`.

5. **Statut des events Google.**
   - `status="confirmed"` (normal) → upsert BusyBlock.
   - `status="cancelled"` (suppression Google) → delete BusyBlock matchant `(google_account_id, source_id=eventId)`.
   - `status="tentative"` → traiter comme confirmé en V1 (un commercial tente, le créneau est bloqué).

6. **Vérification du `X-Goog-Channel-Token`.** Quand on crée le watch channel, on génère un secret aléatoire 32 octets (hex), on le passe à Google dans le champ `token`. Google nous le renvoie à chaque notification dans le header `X-Goog-Channel-Token`. On compare en `hash_equals()` avec le secret stocké (`watch_token_secret`). Si non-match → 401 silencieux, on n'enfile pas de job.

7. **Debounce du pull.** Google peut envoyer plusieurs webhooks rapprochés (un seul utilisateur qui drag-and-drop = 3 notifs). On enfile le job `sb/google_pull` via `as_schedule_single_action(time()+5, 'sb/google_pull', [$accountId], 'slashbooking')`. Les `single_action` Action Scheduler avec mêmes args + même hook + délai court tendent à être dédupliqués naturellement (AS regroupe les claims). En V1 : pas de lock applicatif — un éventuel double-pull est idempotent (mêmes upserts via UNIQUE KEY). Si le doublonnage gêne en prod (logs verbeux), envisager un transient `sb_pull_lock_{accountId}` TTL 60 s en V2 — repoussé.

8. **Idempotence du pull.** Chaque event Google a un `id` stable. Upsert via `UNIQUE KEY (source, source_id)` (déjà migré Plan 1). Pas de doublon possible. `last_synced_at` mis à jour à chaque pass — utile pour debug + cleanup futur.

9. **Cron fallback `sb/google_pull_all`.** Toutes les 15 min, parcourt tous les `GoogleAccount` (V1 : un seul) et lance `SyncEngine::pull`. No-op si le watch fonctionne déjà (le `syncToken` est à jour, Google ne renvoie aucun event). Couvre le cas firewall / DNS / HTTPS chez l'utilisateur (cf. §15 spec).

10. **Cron renouvellement `sb/watch_renew_check`.** Quotidien : si `watch_expires_at < now+1day` ET compte connecté, on `stopChannel(old)` puis `watchChannel(new)`. Cadence quotidienne plutôt que tous les 6 jours strict : ça nous donne 6 fenêtres de rattrapage avant l'expiration réelle (la spec §6.6 nomme ce cron `sb/watch_renew` ; on a renommé en `_check` car il décide de renouveler ou pas — c'est volontaire).

11. **Webhook URL.** `rest_url(Plugin::REST_NAMESPACE . '/google/webhook')`. Doit être HTTPS public (Google rejette `http://` et les IPs privées). En dev local : tunnel ngrok / Cloudflare Tunnel — documenté README. Le doctor signale si l'URL résout sur un domaine non-HTTPS.

12. **Sécurité webhook.** L'endpoint est **public** (Google ne s'authentifie pas avec un cookie WP). La sécurité repose entièrement sur (a) HTTPS, (b) `X-Goog-Channel-Token` vérifié contre `watch_token_secret`. On loggue toute requête rejetée (`direction=internal`, `action=webhook_rejected`).

13. **`BusyBlock::source = 'google'`.** On force ce literal. `source_id` = l'ID Google de l'event. `google_account_id` = id de notre compte. Pour la V2 multi-compte ça reste correct (sourceId + googleAccountId unique).

14. **`stopChannel` peut échouer.** Quand on stoppe l'ancien channel pour renouveler, Google peut renvoyer 404 ou 410 (déjà expiré côté Google). On ignore proprement ces deux codes (comme `deleteEvent` Plan 3).

15. **PHPStan 2.x.** Upgrade en **task 1**. Apporte level 10 dispo + `list<T>` distinct + meilleurs messages. L'extension `szepeviktor/phpstan-wordpress` doit être en `^2.0`. Le `phpstan.neon` reste compatible (mêmes paramètres). Si la suite passe — OK. Sinon ajuster `ignoreErrors`.

16. **Pas de PHP-Scoper dans ce plan.** Toujours repoussé au Plan 5 (packaging final).

---

## File Structure (Plan 4 scope)

```
plugins-booking/
├── composer.json                                # MODIFY — bump phpstan + phpstan-wordpress to 2.x
├── composer.lock                                # AUTO — composer update
├── README.md                                    # MODIFY — document webhook URL + tunnel HTTPS + watch renewal
├── src/
│   ├── Plugin.php                               # MODIFY — wire SyncEngine, watch manager, Action Scheduler handler, cron callbacks
│   ├── Activator.php                            # MODIFY — schedule sb/watch_renew_check + sb/google_pull_all
│   ├── Deactivator.php                          # MODIFY — unschedule new crons + stop watch channel
│   ├── Domain/
│   │   ├── BusyBlock.php                        # MODIFY — add fromGoogleEvent factory + lastSyncedAt + ctor with id-less
│   │   └── GoogleAccount.php                    # MODIFY — attachWatch/clearWatch/verifyWatchToken/updateSyncToken/markFullSyncedAt
│   ├── Google/
│   │   ├── CalendarGateway.php                  # MODIFY — add listEvents + watchChannel + stopChannel
│   │   ├── GoogleApiCalendarGateway.php         # MODIFY — impl new methods
│   │   ├── WatchChannelManager.php              # NEW — start/stop/renew watch + persist secret
│   │   ├── SyncEngine.php                       # NEW — pull events.list + upsert busy blocks + reflect detection
│   │   ├── PullEventJob.php                     # NEW — Action Scheduler handler `sb/google_pull`
│   │   └── Exceptions/
│   │       └── SyncTokenExpired.php             # NEW — 410 Gone marker
│   ├── Persistence/
│   │   ├── BusyBlockRepository.php              # MODIFY — upsertFromGoogle, deleteBySourceId, findBySourceId
│   │   ├── BookingRepository.php                # MODIFY — add findByGoogleEventId (for reflection detection)
│   │   └── GoogleAccountRepository.php          # MODIFY — toRow() persists watch_resource_id, watch_token_secret, last_full_sync_at
│   ├── Http/
│   │   ├── GoogleWebhookController.php          # NEW — POST /google/webhook
│   │   ├── AdminGoogleController.php            # MODIFY — expose /admin/google/watch/start, /watch/stop, /pull/now
│   │   └── RestRouter.php                       # MODIFY — register webhook + new admin endpoints
│   ├── Admin/
│   │   └── react-app/src/
│   │       ├── GooglePage.jsx                   # MODIFY — watch status block + "Force pull" + "Start watch" buttons
│   │       └── api.js                           # MODIFY — startWatch/stopWatch/forcePull/fetchDiagnostics
│   └── Cli/
│       └── DoctorCommand.php                    # MODIFY — add watch + last sync probes
├── tests/
│   ├── Unit/
│   │   ├── Google/
│   │   │   ├── WatchChannelManagerTest.php      # NEW
│   │   │   ├── SyncEngineTest.php               # NEW
│   │   │   └── PullEventJobTest.php             # NEW
│   │   ├── Domain/
│   │   │   └── GoogleAccountTest.php            # NEW (watch state transitions)
│   │   └── Support/
│   │       └── FakeCalendarGateway.php          # MODIFY — listEvents/watchChannel/stopChannel
│   └── Integration/
│       ├── BusyBlockRepositoryTest.php          # NEW
│       ├── GoogleWebhookControllerTest.php      # NEW
│       └── BookingRepositoryTest.php            # MODIFY — add findByGoogleEventId test
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

## Task 1 : Upgrade PHPStan 1.x → 2.x

Préreq tooling avant d'écrire du nouveau code Google. Mémoire `project_post_plan3_actions.md` documente la motivation.

**Files:**
- Modify: `composer.json`
- Auto: `composer.lock`, `vendor/`
- Modify (peut-être): `phpstan.neon`

- [ ] **Step 1 : Modifier `composer.json`**

Dans `"require-dev"`, remplacer :

```json
"phpstan/phpstan": "^1.10",
"szepeviktor/phpstan-wordpress": "^1.3",
```

par :

```json
"phpstan/phpstan": "^2.1",
"szepeviktor/phpstan-wordpress": "^2.0",
```

- [ ] **Step 2 : Update**

```bash
composer update phpstan/phpstan szepeviktor/phpstan-wordpress --with-dependencies
```

Attendu : PHPStan 2.x + extension WordPress 2.x installées.

- [ ] **Step 3 : Lancer PHPStan**

```bash
composer stan
```

Cas 1 — `0 errors` : passer à l'étape 5.

Cas 2 — nouvelles erreurs (probable : `list<T>` vs `array<int, T>`, `@phpstan-pure`, types stricts). Pour chaque erreur :
- Si vraie erreur → corriger le code.
- Si faux positif → ajouter à `ignoreErrors` dans `phpstan.neon` avec **un commentaire** expliquant pourquoi.

- [ ] **Step 4 (conditionnel) : Ajuster `phpstan.neon`**

Si beaucoup d'erreurs sur `list<T>`, ajouter au début de `parameters` :

```neon
parameters:
  level: 8
  treatPhpDocTypesAsCertain: false
```

Ne **pas** descendre le niveau. Préférer fixer le code ou ajouter des `ignoreErrors` ciblés.

- [ ] **Step 5 : Vérifier l'ensemble de la suite**

```bash
composer test
composer cs
composer stan
```

Tout doit être vert. Si tests cassés → c'est qu'un fix de typage a introduit une régression : fix avant de continuer.

- [ ] **Step 6 : Commit**

```bash
git add composer.json composer.lock phpstan.neon
git commit -m "build: upgrade phpstan to 2.x with wordpress extension 2.x"
```

---

## Task 2 : Étendre `CalendarGateway` — listEvents + watchChannel + stopChannel

Ajout des 3 méthodes nécessaires au pull et au watch. **Interface seulement** ici — les impls suivent en Tasks 3-4.

**Files:**
- Modify: `src/Google/CalendarGateway.php`

- [ ] **Step 1 : Réécrire `src/Google/CalendarGateway.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

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
 * @phpstan-type RemoteEvent array{
 *   id: string,
 *   status: string,
 *   summary: string,
 *   start: ?string,
 *   end: ?string,
 *   updated: string,
 *   etag: string
 * }
 * @phpstan-type EventsPage array{
 *   items: list<RemoteEvent>,
 *   nextPageToken: ?string,
 *   nextSyncToken: ?string
 * }
 * @phpstan-type WatchChannelRef array{
 *   channelId: string,
 *   resourceId: string,
 *   expiration: int
 * }
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

    /**
     * List events using either a syncToken (incremental) or a pageToken (full sync continuation).
     * If both are null → fresh full sync.
     *
     * @return EventsPage
     */
    public function listEvents(
        string $calendarId,
        ?string $syncToken = null,
        ?string $pageToken = null
    ): array;

    /**
     * Create a push notification channel for the calendar.
     *
     * @return WatchChannelRef
     */
    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array;

    /**
     * Stop an existing push notification channel.
     */
    public function stopChannel(string $channelId, string $resourceId): void;
}
```

- [ ] **Step 2 : Lancer PHPStan**

```bash
composer stan
```

Attendu : `Method ::listEvents not implemented in GoogleApiCalendarGateway` + `… in FakeCalendarGateway`. Ces erreurs sont temporaires — on les fixe en Tasks 3-4.

Si PHPStan refuse de continuer (erreur fatale), commenter temporairement l'usage et passer.

- [ ] **Step 3 : Commit**

```bash
git add src/Google/CalendarGateway.php
git commit -m "feat(google): extend CalendarGateway with listEvents + watch + stopChannel"
```

---

## Task 3 : Implémenter `listEvents` + `watchChannel` + `stopChannel` dans `GoogleApiCalendarGateway`

**Files:**
- Modify: `src/Google/GoogleApiCalendarGateway.php`
- Create: `src/Google/Exceptions/SyncTokenExpired.php`

- [ ] **Step 1 : Créer `src/Google/Exceptions/SyncTokenExpired.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

/**
 * Marker exception : 410 Gone on events.list — server told us the syncToken is too old / invalid.
 * Caller MUST reset sync_token and retry a full sync.
 */
final class SyncTokenExpired extends \RuntimeException
{
    public function __construct(string $message = 'Sync token expired (HTTP 410)', public readonly int $httpStatus = 410)
    {
        parent::__construct($message);
    }
}
```

- [ ] **Step 2 : Modifier `src/Google/GoogleApiCalendarGateway.php`**

Ajouter en haut du fichier les nouveaux imports :

```php
use Google\Service\Calendar\Channel as CalendarChannel;
use Google\Service\Calendar\Events as CalendarEvents;
use Google\Service\Calendar\Event as CalendarEvent;
use Slash\Booking\Google\Exceptions\SyncTokenExpired;
```

Ajouter les trois méthodes avant la méthode `call` privée :

```php
    public function listEvents(string $calendarId, ?string $syncToken = null, ?string $pageToken = null): array
    {
        try {
            /** @var CalendarEvents $resp */
            $resp = $this->service->events->listEvents($calendarId, array_filter([
                'syncToken'    => $syncToken,
                'pageToken'    => $pageToken,
                'singleEvents' => true,
                'showDeleted'  => true,
                'maxResults'   => 250,
            ], static fn ($v): bool => $v !== null));
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 410) {
                throw new SyncTokenExpired($e->getMessage());
            }
            $code = $e->getCode();
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google list events transient ({$code}): {$e->getMessage()}", $code);
            }
            throw new GoogleClientError("Google list events client error ({$code}): {$e->getMessage()}", $code);
        }

        $items = [];
        foreach ($resp->getItems() as $ev) {
            /** @var CalendarEvent $ev */
            $items[] = [
                'id'      => (string) $ev->getId(),
                'status'  => (string) ($ev->getStatus() ?? 'confirmed'),
                'summary' => (string) ($ev->getSummary() ?? ''),
                'start'   => $ev->getStart()?->getDateTime() ?? $ev->getStart()?->getDate() ?? null,
                'end'     => $ev->getEnd()?->getDateTime() ?? $ev->getEnd()?->getDate() ?? null,
                'updated' => (string) $ev->getUpdated(),
                'etag'    => (string) $ev->getEtag(),
            ];
        }

        return [
            'items'         => $items,
            'nextPageToken' => $resp->getNextPageToken() ?: null,
            'nextSyncToken' => $resp->getNextSyncToken() ?: null,
        ];
    }

    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array {
        return $this->call(function () use ($calendarId, $channelId, $address, $token, $ttlSeconds): array {
            $channel = new CalendarChannel([
                'id'         => $channelId,
                'type'       => 'web_hook',
                'address'    => $address,
                'token'      => $token,
                'expiration' => (string) ((time() + $ttlSeconds) * 1000), // ms since epoch
            ]);
            $created = $this->service->events->watch($calendarId, $channel);
            return [
                'channelId'  => (string) $created->getId(),
                'resourceId' => (string) $created->getResourceId(),
                'expiration' => (int) ((int) $created->getExpiration() / 1000),
            ];
        });
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        try {
            $channel = new CalendarChannel(['id' => $channelId, 'resourceId' => $resourceId]);
            $this->service->channels->stop($channel);
        } catch (GoogleServiceException $e) {
            $code = $e->getCode();
            if ($code === 404 || $code === 410) {
                return; // Already gone — treat as success.
            }
            if ($code >= 500 || $code === 429) {
                throw new GoogleApiError("Google stop channel transient ({$code}): {$e->getMessage()}", $code);
            }
            throw new GoogleClientError("Google stop channel client error ({$code}): {$e->getMessage()}", $code);
        }
    }
```

Ajuster la signature de retour du `call` privé existant pour ne **pas** retourner `WatchChannelRef` (l'ancien shape reste `EventRef`). Note : `watchChannel` retourne un shape distinct mais le helper `call` accepte n'importe quel `array` en pratique — si PHPStan râle, déclarer un type union ou typé large :

```php
    /**
     * @template T of array
     * @param callable(): T $fn
     * @return T
     */
    private function call(callable $fn): array
```

- [ ] **Step 3 : Lancer PHPStan**

```bash
composer stan
```

Attendu : warnings sur `Channel` / `Events` si le SDK Google n'est pas autoloadé en CI. Si oui, ajouter dans `phpstan.neon` :

```neon
parameters:
  ignoreErrors:
    -
      identifier: class.notFound
      paths:
        - src/Google/GoogleApiCalendarGateway.php
```

Sinon, on garde la cible 0 erreur.

- [ ] **Step 4 : Commit**

```bash
git add src/Google/GoogleApiCalendarGateway.php src/Google/Exceptions/SyncTokenExpired.php phpstan.neon
git commit -m "feat(google): implement listEvents + watchChannel + stopChannel via Google SDK"
```

---

## Task 4 : Étendre `FakeCalendarGateway` avec les nouvelles méthodes

**Files:**
- Modify: `tests/Unit/Support/FakeCalendarGateway.php`

- [ ] **Step 1 : Réécrire `tests/Unit/Support/FakeCalendarGateway.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Support;

use Slash\Booking\Google\CalendarGateway;
use Slash\Booking\Google\Exceptions\GoogleApiError;
use Slash\Booking\Google\Exceptions\GoogleClientError;
use Slash\Booking\Google\Exceptions\SyncTokenExpired;

final class FakeCalendarGateway implements CalendarGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $events = [];

    /** @var list<array{op:string, calendar:string, payload?:mixed, eventId?:string, syncToken?:?string, pageToken?:?string, channelId?:string, resourceId?:string}> */
    public array $calls = [];

    private int $seq = 0;

    public bool $failNext = false;

    public ?int $throwClientErrorOnDelete = null;

    public bool $throwSyncTokenExpiredNext = false;

    /** @var list<array<string, mixed>> Pages queued for listEvents (FIFO). */
    public array $pages = [];

    /** @var list<array{channelId:string, resourceId:string}> stopped channels */
    public array $stoppedChannels = [];

    /** @var list<array{calendar:string, channelId:string, address:string, token:string, ttl:int}> */
    public array $startedChannels = [];

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
            throw new GoogleClientError('Event not found', 404);
        }
        $this->events[$eventId]['payload'] = $payload;
        $this->events[$eventId]['etag'] = '"etag_patched_' . (++$this->seq) . '"';
        return ['id' => $eventId, 'etag' => $this->events[$eventId]['etag']];
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->calls[] = ['op' => 'delete', 'calendar' => $calendarId, 'eventId' => $eventId];
        if ($this->throwClientErrorOnDelete !== null) {
            $code = $this->throwClientErrorOnDelete;
            $this->throwClientErrorOnDelete = null;
            throw new GoogleClientError("Simulated {$code}", $code);
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        unset($this->events[$eventId]);
    }

    public function listEvents(string $calendarId, ?string $syncToken = null, ?string $pageToken = null): array
    {
        $this->calls[] = [
            'op'        => 'list',
            'calendar'  => $calendarId,
            'syncToken' => $syncToken,
            'pageToken' => $pageToken,
        ];
        if ($this->throwSyncTokenExpiredNext) {
            $this->throwSyncTokenExpiredNext = false;
            throw new SyncTokenExpired('Simulated 410');
        }
        if ($this->failNext) {
            $this->failNext = false;
            throw new GoogleApiError('Simulated 503', 503);
        }
        // Pop next queued page; if none, return empty.
        $page = array_shift($this->pages);
        if ($page === null) {
            return ['items' => [], 'nextPageToken' => null, 'nextSyncToken' => 'sync_' . (++$this->seq)];
        }
        return $page;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function queuePage(array $items, ?string $nextPageToken = null, ?string $nextSyncToken = null): void
    {
        $this->pages[] = [
            'items'         => $items,
            'nextPageToken' => $nextPageToken,
            'nextSyncToken' => $nextSyncToken,
        ];
    }

    public function watchChannel(
        string $calendarId,
        string $channelId,
        string $address,
        string $token,
        int $ttlSeconds
    ): array {
        $this->startedChannels[] = compact('calendarId', 'channelId', 'address', 'token') + ['ttl' => $ttlSeconds];
        $this->calls[] = ['op' => 'watch', 'calendar' => $calendarId, 'channelId' => $channelId];
        return [
            'channelId'  => $channelId,
            'resourceId' => 'res_' . $channelId,
            'expiration' => time() + $ttlSeconds,
        ];
    }

    public function stopChannel(string $channelId, string $resourceId): void
    {
        $this->stoppedChannels[] = ['channelId' => $channelId, 'resourceId' => $resourceId];
        $this->calls[] = ['op' => 'stop', 'channelId' => $channelId, 'resourceId' => $resourceId];
    }
}
```

- [ ] **Step 2 : Lancer la suite unit**

```bash
composer test
```

Attendu : tous les tests Plan 3 toujours verts. Si erreur de signature → re-vérifier que les méthodes manquantes ont la même signature que l'interface.

- [ ] **Step 3 : Commit**

```bash
git add tests/Unit/Support/FakeCalendarGateway.php
git commit -m "test(google): extend FakeCalendarGateway with list/watch/stop methods"
```

---

## Task 5 : Étendre `BusyBlock` Domain — factory `fromGoogleEvent` + `lastSyncedAt`

**Files:**
- Modify: `src/Domain/BusyBlock.php`
- Create: `tests/Unit/Domain/BusyBlockTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Domain/BusyBlockTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Domain\TimeSlot;

final class BusyBlockTest extends TestCase
{
    public function test_from_google_event_builds_block_with_utc_slot(): void
    {
        $bb = BusyBlock::fromGoogleEvent(
            googleAccountId: 7,
            eventId: 'gcal_abc',
            start: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
            end: new DateTimeImmutable('2026-06-01T10:00:00+02:00'),
            summary: 'Atelier interne',
            syncedAt: new DateTimeImmutable('2026-05-20T08:00:00Z'),
        );

        self::assertNull($bb->id);
        self::assertSame('google', $bb->source);
        self::assertSame('gcal_abc', $bb->sourceId);
        self::assertSame(7, $bb->googleAccountId);
        self::assertSame('Atelier interne', $bb->summary);
        self::assertSame('UTC', $bb->slot->start->getTimezone()->getName());
        self::assertSame('2026-06-01 07:00:00', $bb->slot->start->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01 08:00:00', $bb->slot->end->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-20 08:00:00', $bb->lastSyncedAt->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter BusyBlockTest
```

Attendu : `Method BusyBlock::fromGoogleEvent does not exist` (ou `property lastSyncedAt not found`).

- [ ] **Step 3 : Modifier `src/Domain/BusyBlock.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class BusyBlock
{
    public function __construct(
        public ?int $id,
        public string $source,
        public string $sourceId,
        public ?int $googleAccountId,
        public TimeSlot $slot,
        public string $summary,
        public ?DateTimeImmutable $lastSyncedAt = null,
    ) {
    }

    public static function fromGoogleEvent(
        int $googleAccountId,
        string $eventId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $summary,
        ?DateTimeImmutable $syncedAt = null,
    ): self {
        $utc = new DateTimeZone('UTC');
        return new self(
            id: null,
            source: 'google',
            sourceId: $eventId,
            googleAccountId: $googleAccountId,
            slot: new TimeSlot($start->setTimezone($utc), $end->setTimezone($utc)),
            summary: $summary,
            lastSyncedAt: $syncedAt?->setTimezone($utc) ?? new DateTimeImmutable('now', $utc),
        );
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter BusyBlockTest
```

- [ ] **Step 5 : Vérifier que `BusyBlockRepository::findInRange()` (Plan 1) reste compatible**

Le ctor a un nouveau paramètre optionnel `?DateTimeImmutable $lastSyncedAt = null`. Les appels existants restent valides. Lancer :

```bash
composer test
composer stan
```

- [ ] **Step 6 : Commit**

```bash
git add src/Domain/BusyBlock.php tests/Unit/Domain/BusyBlockTest.php
git commit -m "feat(domain): BusyBlock::fromGoogleEvent factory + lastSyncedAt"
```

---

## Task 6 : Étendre `BusyBlockRepository` — upsert, delete, findBySourceId

**Files:**
- Modify: `src/Persistence/BusyBlockRepository.php`
- Create: `tests/Integration/BusyBlockRepositoryTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

`tests/Integration/BusyBlockRepositoryTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Persistence\BusyBlockRepository;

final class BusyBlockRepositoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            self::markTestSkipped('WP test suite not available.');
        }
    }

    public function test_upsert_inserts_new_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $bb = BusyBlock::fromGoogleEvent(
            googleAccountId: 1,
            eventId: 'gcal_a',
            start: new DateTimeImmutable('2026-06-01 09:00:00', new DateTimeZone('UTC')),
            end:   new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')),
            summary: 'Demo',
        );
        $repo->upsertFromGoogle($bb);

        $found = $repo->findBySourceId(1, 'gcal_a');
        self::assertNotNull($found);
        self::assertSame('Demo', $found->summary);
    }

    public function test_upsert_updates_existing_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $start = new DateTimeImmutable('2026-06-02 09:00:00', new DateTimeZone('UTC'));
        $end   = new DateTimeImmutable('2026-06-02 10:00:00', new DateTimeZone('UTC'));

        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(1, 'gcal_b', $start, $end, 'v1'));
        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(1, 'gcal_b', $start, $end, 'v2'));

        $found = $repo->findBySourceId(1, 'gcal_b');
        self::assertNotNull($found);
        self::assertSame('v2', $found->summary);
    }

    public function test_delete_by_source_id_removes_block(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);

        $repo->upsertFromGoogle(BusyBlock::fromGoogleEvent(
            1,
            'gcal_c',
            new DateTimeImmutable('2026-06-03 09:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-03 10:00:00', new DateTimeZone('UTC')),
            'X',
        ));
        $repo->deleteBySourceId(1, 'gcal_c');

        self::assertNull($repo->findBySourceId(1, 'gcal_c'));
    }

    public function test_delete_no_op_if_missing(): void
    {
        global $wpdb;
        $repo = new BusyBlockRepository($wpdb);
        // Should not throw.
        $repo->deleteBySourceId(1, 'gcal_nonexistent');
        self::assertTrue(true);
    }
}
```

- [ ] **Step 2 : Lancer → rouge ou skipped**

```bash
composer test:integration -- --filter BusyBlockRepositoryTest
```

Attendu : `skipped (WP test suite not available)` OU `Method upsertFromGoogle does not exist`.

- [ ] **Step 3 : Modifier `src/Persistence/BusyBlockRepository.php`**

Réécrire le fichier complet :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Persistence;

use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final class BusyBlockRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'sb_busy_blocks';
    }

    /**
     * @return list<BusyBlock>
     */
    public function findInRange(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE starts_at_utc < %s AND ends_at_utc > %s
                  ORDER BY starts_at_utc",
                $toUtc->format('Y-m-d H:i:s'),
                $fromUtc->format('Y-m-d H:i:s'),
            ),
            ARRAY_A
        );
        return $this->hydrateMany(is_array($rows) ? $rows : []);
    }

    public function findBySourceId(int $googleAccountId, string $sourceId): ?BusyBlock
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE source = 'google' AND google_account_id = %d AND source_id = %s LIMIT 1",
                $googleAccountId,
                $sourceId,
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return $this->hydrateOne($row);
    }

    public function upsertFromGoogle(BusyBlock $block): void
    {
        if ($block->googleAccountId === null) {
            throw new \InvalidArgumentException('BusyBlock from Google requires googleAccountId.');
        }
        $existing = $this->findBySourceId($block->googleAccountId, $block->sourceId);
        $row = [
            'source'             => 'google',
            'source_id'          => $block->sourceId,
            'google_account_id'  => $block->googleAccountId,
            'starts_at_utc'      => $block->slot->start->format('Y-m-d H:i:s'),
            'ends_at_utc'        => $block->slot->end->format('Y-m-d H:i:s'),
            'summary'            => $block->summary,
            'last_synced_at'     => ($block->lastSyncedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];
        if ($existing === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->wpdb->insert($this->table, $row);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->update($this->table, $row, ['id' => $existing->id]);
    }

    public function deleteBySourceId(int $googleAccountId, string $sourceId): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->delete($this->table, [
            'source'            => 'google',
            'google_account_id' => $googleAccountId,
            'source_id'         => $sourceId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<BusyBlock>
     */
    private function hydrateMany(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateOne($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateOne(array $row): BusyBlock
    {
        $utc = new DateTimeZone('UTC');
        return new BusyBlock(
            id: (int) $row['id'],
            source: (string) $row['source'],
            sourceId: (string) $row['source_id'],
            googleAccountId: $row['google_account_id'] !== null ? (int) $row['google_account_id'] : null,
            slot: new TimeSlot(
                new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
                new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
            ),
            summary: (string) ($row['summary'] ?? ''),
            lastSyncedAt: isset($row['last_synced_at']) && $row['last_synced_at'] !== null
                ? new DateTimeImmutable((string) $row['last_synced_at'], $utc)
                : null,
        );
    }
}
```

- [ ] **Step 4 : Lancer → vert (ou skipped propre)**

```bash
composer test:integration -- --filter BusyBlockRepositoryTest
```

Si wp-phpunit pas installé : `skipped` → OK.

- [ ] **Step 5 : Vérifier le reste de la suite**

```bash
composer test && composer stan && composer cs
```

- [ ] **Step 6 : Commit**

```bash
git add src/Persistence/BusyBlockRepository.php tests/Integration/BusyBlockRepositoryTest.php
git commit -m "feat(persistence): BusyBlockRepository upsert/delete/find by Google source"
```

---

## Task 7 : Étendre `Domain/GoogleAccount` — watch + sync token transitions

**Files:**
- Modify: `src/Domain/GoogleAccount.php`
- Create: `tests/Unit/Domain/GoogleAccountTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Domain/GoogleAccountTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;

final class GoogleAccountTest extends TestCase
{
    private function fresh(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        return GoogleAccount::connect(
            label: 'Commercial',
            calendarId: 'primary',
            refreshTokenEnc: 'refresh',
            accessTokenEnc: 'access',
            expiresAt: new DateTimeImmutable('+1 hour', $utc),
        );
    }

    public function test_attach_watch_sets_all_fields(): void
    {
        $a = $this->fresh();
        $a->attachWatch(
            channelId: 'ch_1',
            resourceId: 'res_1',
            tokenSecret: 'secret_xyz',
            expiresAt: new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame('ch_1', $a->watchChannelId());
        self::assertSame('res_1', $a->watchResourceId());
        self::assertSame('secret_xyz', $a->watchTokenSecret());
        self::assertSame('2026-06-01 10:00:00', $a->watchExpiresAt()?->format('Y-m-d H:i:s'));
    }

    public function test_clear_watch_resets_fields(): void
    {
        $a = $this->fresh();
        $a->attachWatch('ch', 'res', 'tok', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        $a->clearWatch();

        self::assertNull($a->watchChannelId());
        self::assertNull($a->watchResourceId());
        self::assertNull($a->watchTokenSecret());
        self::assertNull($a->watchExpiresAt());
    }

    public function test_verify_watch_token_uses_hash_equals(): void
    {
        $a = $this->fresh();
        self::assertFalse($a->verifyWatchToken('anything')); // no watch yet
        $a->attachWatch('ch', 'res', 'correct_secret', new DateTimeImmutable('+7 days', new DateTimeZone('UTC')));
        self::assertTrue($a->verifyWatchToken('correct_secret'));
        self::assertFalse($a->verifyWatchToken('wrong'));
        self::assertFalse($a->verifyWatchToken(''));
    }

    public function test_update_sync_token(): void
    {
        $a = $this->fresh();
        self::assertNull($a->syncToken());
        $a->updateSyncToken('CAESDQjs');
        self::assertSame('CAESDQjs', $a->syncToken());
    }

    public function test_clear_sync_token(): void
    {
        $a = $this->fresh();
        $a->updateSyncToken('something');
        $a->clearSyncToken();
        self::assertNull($a->syncToken());
    }

    public function test_mark_full_synced_at(): void
    {
        $a = $this->fresh();
        $when = new DateTimeImmutable('2026-05-20 12:00:00', new DateTimeZone('UTC'));
        $a->markFullSyncedAt($when);
        self::assertSame('2026-05-20 12:00:00', $a->lastFullSyncAt()?->format('Y-m-d H:i:s'));
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter GoogleAccountTest
```

Attendu : `Method GoogleAccount::attachWatch does not exist`.

- [ ] **Step 3 : Étendre `src/Domain/GoogleAccount.php`**

Ajouter ces méthodes avant le `private function touch()` :

```php
    public function attachWatch(
        string $channelId,
        string $resourceId,
        string $tokenSecret,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->watchChannelId   = $channelId;
        $this->watchResourceId  = $resourceId;
        $this->watchTokenSecret = $tokenSecret;
        $this->watchExpiresAt   = $expiresAt;
        $this->touch();
    }

    public function clearWatch(): void
    {
        $this->watchChannelId   = null;
        $this->watchResourceId  = null;
        $this->watchTokenSecret = null;
        $this->watchExpiresAt   = null;
        $this->touch();
    }

    public function verifyWatchToken(string $candidate): bool
    {
        if ($this->watchTokenSecret === null || $candidate === '') {
            return false;
        }
        return hash_equals($this->watchTokenSecret, $candidate);
    }

    public function updateSyncToken(string $token): void
    {
        $this->syncToken = $token;
        $this->touch();
    }

    public function clearSyncToken(): void
    {
        $this->syncToken = null;
        $this->touch();
    }

    public function markFullSyncedAt(DateTimeImmutable $when): void
    {
        $this->lastFullSyncAt = $when;
        $this->touch();
    }
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter GoogleAccountTest
```

- [ ] **Step 5 : Vérifier**

```bash
composer test && composer stan
```

- [ ] **Step 6 : Commit**

```bash
git add src/Domain/GoogleAccount.php tests/Unit/Domain/GoogleAccountTest.php
git commit -m "feat(domain): GoogleAccount watch + sync token state transitions"
```

---

## Task 8 : Compléter `GoogleAccountRepository::toRow()` (bug latent Plan 3)

Le `toRow()` actuel n'écrit **pas** `watch_resource_id`, `watch_token_secret`, ni `last_full_sync_at`. Bug silencieux qui n'a pas pété en Plan 3 parce qu'on ne les utilisait jamais.

**Files:**
- Modify: `src/Persistence/GoogleAccountRepository.php`
- Modify: `tests/Integration/GoogleAccountRepositoryTest.php` (existe déjà — ajouter un test)

- [ ] **Step 1 : Ajouter un test dans `tests/Integration/GoogleAccountRepositoryTest.php`**

Ajouter (au minimum) cette méthode :

```php
public function test_save_persists_watch_and_sync_state(): void
{
    global $wpdb;
    $repo = new \Slash\Booking\Persistence\GoogleAccountRepository($wpdb);
    $utc  = new \DateTimeZone('UTC');

    $a = \Slash\Booking\Domain\GoogleAccount::connect(
        label: 'X',
        calendarId: 'primary',
        refreshTokenEnc: 'r',
        accessTokenEnc: 'a',
        expiresAt: new \DateTimeImmutable('+1 hour', $utc),
    );
    $repo->save($a);

    $a->attachWatch('ch_1', 'res_1', 'sec_1', new \DateTimeImmutable('+7 days', $utc));
    $a->updateSyncToken('sync_xyz');
    $a->markFullSyncedAt(new \DateTimeImmutable('2026-05-20 10:00:00', $utc));
    $repo->save($a);

    $reloaded = $repo->findById((int) $a->id());
    self::assertNotNull($reloaded);
    self::assertSame('ch_1', $reloaded->watchChannelId());
    self::assertSame('res_1', $reloaded->watchResourceId());
    self::assertSame('sec_1', $reloaded->watchTokenSecret());
    self::assertSame('sync_xyz', $reloaded->syncToken());
    self::assertSame('2026-05-20 10:00:00', $reloaded->lastFullSyncAt()?->format('Y-m-d H:i:s'));
}
```

- [ ] **Step 2 : Lancer → rouge ou skipped**

```bash
composer test:integration -- --filter GoogleAccountRepositoryTest::test_save_persists_watch_and_sync_state
```

Attendu : champs `watch_resource_id` à `NULL` en base, le test échoue (sauf si wp-phpunit absent → skipped).

- [ ] **Step 3 : Compléter `toRow()` dans `src/Persistence/GoogleAccountRepository.php`**

Remplacer la méthode `toRow()` par :

```php
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
            'watch_resource_id'        => $a->watchResourceId(),
            'watch_token_secret'       => $a->watchTokenSecret(),
            'watch_expires_at'         => $fmt($a->watchExpiresAt()),
            'sync_token'               => $a->syncToken(),
            'last_full_sync_at'        => $fmt($a->lastFullSyncAt()),
            'created_at'               => $fmt($a->createdAt()),
            'updated_at'               => $fmt($a->updatedAt()),
        ];
    }
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test:integration -- --filter GoogleAccountRepositoryTest
composer test && composer stan && composer cs
```

- [ ] **Step 5 : Commit**

```bash
git add src/Persistence/GoogleAccountRepository.php tests/Integration/GoogleAccountRepositoryTest.php
git commit -m "fix(persistence): persist watch_resource_id + watch_token_secret + last_full_sync_at"
```

---

## Task 9 : `BookingRepository::findByGoogleEventId()` (détection reflection)

Pour que le `SyncEngine` puisse ignorer ses propres pushes, il faut interroger `BookingRepository` par `google_event_id`.

**Files:**
- Modify: `src/Persistence/BookingRepository.php`
- Modify: `tests/Integration/BookingRepositoryTest.php`

- [ ] **Step 1 : Lire la signature existante de `BookingRepository`**

```bash
grep -n "public function" src/Persistence/BookingRepository.php
```

Repérer une méthode `findById` similaire pour reproduire le style (SELECT * + hydrate).

- [ ] **Step 2 : Ajouter un test**

Dans `tests/Integration/BookingRepositoryTest.php`, ajouter :

```php
public function test_find_by_google_event_id_returns_booking(): void
{
    global $wpdb;
    $repo = new \Slash\Booking\Persistence\BookingRepository($wpdb);

    // Use an existing fixture from earlier tests OR insert a booking row with google_event_id set.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->insert($wpdb->prefix . 'sb_bookings', [
        'public_uid'       => 'uid-' . uniqid(),
        'service_id'       => 1,
        'status'           => 'confirmed',
        'starts_at_utc'    => '2026-06-01 09:00:00',
        'ends_at_utc'      => '2026-06-01 10:30:00',
        'timezone'         => 'Europe/Paris',
        'customer_name'    => 'X',
        'customer_email'   => 'x@example.com',
        'customer_phone'   => '0600000000',
        'google_event_id'  => 'gcal_known_1',
        'created_at'       => '2026-05-20 00:00:00',
        'updated_at'       => '2026-05-20 00:00:00',
    ]);
    $found = $repo->findByGoogleEventId('gcal_known_1');
    self::assertNotNull($found);

    self::assertNull($repo->findByGoogleEventId('gcal_unknown_404'));
}
```

- [ ] **Step 3 : Lancer → rouge ou skipped**

```bash
composer test:integration -- --filter BookingRepositoryTest::test_find_by_google_event_id_returns_booking
```

- [ ] **Step 4 : Ajouter la méthode dans `src/Persistence/BookingRepository.php`**

Trouver `findById()` et insérer juste après :

```php
    public function findByGoogleEventId(string $googleEventId): ?\Slash\Booking\Domain\Booking
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE google_event_id = %s LIMIT 1",
                $googleEventId,
            ),
            ARRAY_A
        );
        return is_array($row) ? $this->hydrate($row) : null;
    }
```

(Remplacer `$this->hydrate($row)` par le nom réel de la méthode d'hydration. La trouver via `grep "private function" src/Persistence/BookingRepository.php`.)

- [ ] **Step 5 : Lancer → vert**

```bash
composer test:integration -- --filter BookingRepositoryTest
composer test && composer stan && composer cs
```

- [ ] **Step 6 : Commit**

```bash
git add src/Persistence/BookingRepository.php tests/Integration/BookingRepositoryTest.php
git commit -m "feat(persistence): BookingRepository::findByGoogleEventId for reflection detection"
```

---

## Task 10 : `Google\WatchChannelManager` — start / stop / renew

**Files:**
- Create: `src/Google/WatchChannelManager.php`
- Create: `tests/Unit/Google/WatchChannelManagerTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Google/WatchChannelManagerTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\WatchChannelManager;
use Slash\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class WatchChannelManagerTest extends TestCase
{
    private function freshAccount(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        $a = GoogleAccount::connect('lbl', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        // Assign an id so save() acts as update — we ignore persistence here via the closure.
        $a->assignId(1);
        return $a;
    }

    public function test_start_creates_channel_and_persists_account(): void
    {
        $gateway = new FakeCalendarGateway();
        $saved   = null;
        $mgr = new WatchChannelManager(
            persist: function (GoogleAccount $a) use (&$saved): void {
                $saved = $a;
            },
            ttlSeconds: 7 * 24 * 3600,
        );

        $account = $this->freshAccount();
        $mgr->start($account, $gateway, 'https://example.test/wp-json/slashbooking/v1/google/webhook');

        self::assertNotNull($account->watchChannelId());
        self::assertNotNull($account->watchTokenSecret());
        self::assertSame('res_' . $account->watchChannelId(), $account->watchResourceId());
        self::assertNotNull($saved);

        self::assertCount(1, $gateway->startedChannels);
        self::assertSame('https://example.test/wp-json/slashbooking/v1/google/webhook', $gateway->startedChannels[0]['address']);
        self::assertSame($account->watchTokenSecret(), $gateway->startedChannels[0]['token']);
    }

    public function test_start_stops_previous_channel_first(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();
        $account->attachWatch('old_ch', 'old_res', 'old_secret', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));

        $mgr->start($account, $gateway, 'https://x.test/h');

        self::assertSame([['channelId' => 'old_ch', 'resourceId' => 'old_res']], $gateway->stoppedChannels);
        self::assertNotSame('old_ch', $account->watchChannelId());
    }

    public function test_stop_clears_state(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();
        $account->attachWatch('ch', 'res', 'sec', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));

        $mgr->stop($account, $gateway);

        self::assertNull($account->watchChannelId());
        self::assertSame([['channelId' => 'ch', 'resourceId' => 'res']], $gateway->stoppedChannels);
    }

    public function test_stop_noop_if_no_channel(): void
    {
        $gateway = new FakeCalendarGateway();
        $mgr = new WatchChannelManager(persist: fn () => null, ttlSeconds: 60);
        $account = $this->freshAccount();

        $mgr->stop($account, $gateway);

        self::assertSame([], $gateway->stoppedChannels);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter WatchChannelManagerTest
```

- [ ] **Step 3 : Créer `src/Google/WatchChannelManager.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Domain\GoogleAccount;

final class WatchChannelManager
{
    /**
     * @param Closure(GoogleAccount): void $persist
     * @param int                          $ttlSeconds  TTL of the channel (Google max = 604800 = 7 days)
     */
    public function __construct(
        private readonly Closure $persist,
        private readonly int $ttlSeconds = 604_800,
    ) {
    }

    public function start(GoogleAccount $account, CalendarGateway $gateway, string $webhookUrl): void
    {
        // Stop previous channel if any (best effort; ignore failures by design of stopChannel).
        if ($account->watchChannelId() !== null && $account->watchResourceId() !== null) {
            $gateway->stopChannel($account->watchChannelId(), $account->watchResourceId());
        }

        $channelId   = self::uuidv4();
        $tokenSecret = bin2hex(random_bytes(16)); // 32 hex chars, fits VARCHAR(80)

        $ref = $gateway->watchChannel(
            calendarId: $account->calendarId(),
            channelId: $channelId,
            address: $webhookUrl,
            token: $tokenSecret,
            ttlSeconds: $this->ttlSeconds,
        );

        $expiresAt = (new DateTimeImmutable('@' . $ref['expiration']))->setTimezone(new DateTimeZone('UTC'));
        $account->attachWatch(
            channelId: $ref['channelId'],
            resourceId: $ref['resourceId'],
            tokenSecret: $tokenSecret,
            expiresAt: $expiresAt,
        );
        ($this->persist)($account);
    }

    public function stop(GoogleAccount $account, CalendarGateway $gateway): void
    {
        $channelId  = $account->watchChannelId();
        $resourceId = $account->watchResourceId();
        if ($channelId === null || $resourceId === null) {
            return;
        }
        $gateway->stopChannel($channelId, $resourceId);
        $account->clearWatch();
        ($this->persist)($account);
    }

    public function renew(GoogleAccount $account, CalendarGateway $gateway, string $webhookUrl): void
    {
        // Same logic as start(): stop old + create new.
        $this->start($account, $gateway, $webhookUrl);
    }

    private static function uuidv4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter WatchChannelManagerTest
```

- [ ] **Step 5 : Vérifier**

```bash
composer test && composer stan && composer cs
```

- [ ] **Step 6 : Commit**

```bash
git add src/Google/WatchChannelManager.php tests/Unit/Google/WatchChannelManagerTest.php
git commit -m "feat(google): WatchChannelManager (start/stop/renew watch channel)"
```

---

## Task 11 : `Google\SyncEngine` — pull events.list + upsert busy blocks

C'est le cœur du Plan 4. Pas de WP ici (sauf en wiring) — uniquement closures injectées.

**Files:**
- Create: `src/Google/SyncEngine.php`
- Create: `tests/Unit/Google/SyncEngineTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Google/SyncEngineTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\SyncEngine;
use Slash\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class SyncEngineTest extends TestCase
{
    private function account(): GoogleAccount
    {
        $utc = new DateTimeZone('UTC');
        $a = GoogleAccount::connect('l', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        $a->assignId(1);
        return $a;
    }

    public function test_full_sync_upserts_external_events_only(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [
                [
                    'id'      => 'ev_external',
                    'status'  => 'confirmed',
                    'summary' => 'Atelier',
                    'start'   => '2026-06-01T09:00:00+02:00',
                    'end'     => '2026-06-01T10:00:00+02:00',
                    'updated' => '2026-05-20T10:00:00Z',
                    'etag'    => '"e1"',
                ],
                [
                    'id'      => 'ev_ours',
                    'status'  => 'confirmed',
                    'summary' => 'Booking via plugin',
                    'start'   => '2026-06-02T14:00:00+02:00',
                    'end'     => '2026-06-02T15:30:00+02:00',
                    'updated' => '2026-05-20T11:00:00Z',
                    'etag'    => '"e2"',
                ],
            ],
            nextSyncToken: 'tok_v2',
        );

        $upserts = [];
        $deletes = [];
        $logged  = [];

        $engine = new SyncEngine(
            findBookingByEventId: fn (string $id) => $id === 'ev_ours' ? 42 : null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb;
            },
            deleteBusyBlock: function (int $accountId, string $sourceId) use (&$deletes): void {
                $deletes[] = [$accountId, $sourceId];
            },
            persistAccount: fn () => null,
            log: function (array $entry) use (&$logged): void {
                $logged[] = $entry;
            },
        );

        $account = $this->account();
        $result = $engine->pull($account, $gateway);

        self::assertCount(1, $upserts);
        self::assertSame('ev_external', $upserts[0]->sourceId);
        self::assertSame([], $deletes);
        self::assertSame('tok_v2', $account->syncToken());
        self::assertSame(1, $result->upserted);
        self::assertSame(0, $result->deleted);
        self::assertSame(1, $result->ignoredReflection);
        self::assertNotEmpty($logged);
    }

    public function test_cancelled_event_deletes_busy_block(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [[
                'id'      => 'ev_x',
                'status'  => 'cancelled',
                'summary' => '',
                'start'   => null,
                'end'     => null,
                'updated' => '2026-05-20T10:00:00Z',
                'etag'    => '"e"',
            ]],
            nextSyncToken: 'tok_z',
        );

        $deletes = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: fn () => null,
            deleteBusyBlock: function (int $a, string $id) use (&$deletes): void {
                $deletes[] = [$a, $id];
            },
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $result = $engine->pull($account, $gateway);

        self::assertSame([[1, 'ev_x']], $deletes);
        self::assertSame(1, $result->deleted);
    }

    public function test_paginates_until_no_next_page_token(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->queuePage(
            items: [[
                'id' => 'p1', 'status' => 'confirmed', 'summary' => 'a',
                'start' => '2026-06-01T09:00:00Z', 'end' => '2026-06-01T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextPageToken: 'page_2',
        );
        $gateway->queuePage(
            items: [[
                'id' => 'p2', 'status' => 'confirmed', 'summary' => 'b',
                'start' => '2026-06-02T09:00:00Z', 'end' => '2026-06-02T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextSyncToken: 'final_tok',
        );

        $upserts = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb->sourceId;
            },
            deleteBusyBlock: fn () => null,
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $engine->pull($account, $gateway);

        self::assertSame(['p1', 'p2'], $upserts);
        self::assertSame('final_tok', $account->syncToken());
    }

    public function test_410_gone_resets_sync_token_and_retries_full_sync(): void
    {
        $gateway = new FakeCalendarGateway();
        $gateway->throwSyncTokenExpiredNext = true;
        $gateway->queuePage(
            items: [[
                'id' => 'ev_fresh', 'status' => 'confirmed', 'summary' => 'x',
                'start' => '2026-06-01T09:00:00Z', 'end' => '2026-06-01T10:00:00Z',
                'updated' => '2026-05-20T10:00:00Z', 'etag' => '"e"',
            ]],
            nextSyncToken: 'tok_new',
        );

        $upserts = [];
        $engine = new SyncEngine(
            findBookingByEventId: fn () => null,
            upsertBusyBlock: function (BusyBlock $bb) use (&$upserts): void {
                $upserts[] = $bb->sourceId;
            },
            deleteBusyBlock: fn () => null,
            persistAccount: fn () => null,
            log: fn () => null,
        );
        $account = $this->account();
        $account->updateSyncToken('stale_token');
        $engine->pull($account, $gateway);

        self::assertSame('tok_new', $account->syncToken());
        self::assertSame(['ev_fresh'], $upserts);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter SyncEngineTest
```

- [ ] **Step 3 : Créer `src/Google/SyncEngine.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\SyncTokenExpired;

final class SyncEngine
{
    /**
     * @param Closure(string): ?int            $findBookingByEventId  Return booking id if our reflection, else null
     * @param Closure(BusyBlock): void         $upsertBusyBlock
     * @param Closure(int, string): void       $deleteBusyBlock       (googleAccountId, sourceId)
     * @param Closure(GoogleAccount): void     $persistAccount
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly Closure $findBookingByEventId,
        private readonly Closure $upsertBusyBlock,
        private readonly Closure $deleteBusyBlock,
        private readonly Closure $persistAccount,
        private readonly Closure $log,
    ) {
    }

    public function pull(GoogleAccount $account, CalendarGateway $gateway): PullResult
    {
        $result = new PullResult();
        $accountId = (int) $account->id();
        $calendarId = $account->calendarId();
        $syncToken  = $account->syncToken();
        $pageToken  = null;
        $isFullSync = $syncToken === null;

        try {
            do {
                $page = $gateway->listEvents($calendarId, $syncToken, $pageToken);
                $this->ingestPage($page, $accountId, $result);
                $syncToken = null; // Only used on the first call of the loop.
                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== null);

            if ($page['nextSyncToken'] !== null) {
                $account->updateSyncToken($page['nextSyncToken']);
            }
        } catch (SyncTokenExpired $e) {
            // Reset and retry once as a full sync.
            $account->clearSyncToken();
            $this->logEntry('warn', $accountId, 'sync_token_expired', 'retry', $e->getMessage());

            $pageToken = null;
            $isFullSync = true;
            do {
                $page = $gateway->listEvents($calendarId, null, $pageToken);
                $this->ingestPage($page, $accountId, $result);
                $pageToken = $page['nextPageToken'];
            } while ($pageToken !== null);

            if ($page['nextSyncToken'] !== null) {
                $account->updateSyncToken($page['nextSyncToken']);
            }
        }

        if ($isFullSync) {
            $account->markFullSyncedAt(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
        ($this->persistAccount)($account);

        $this->logEntry(
            level: 'info',
            accountId: $accountId,
            action: 'pull',
            status: 'ok',
            error: null,
            extra: [
                'upserted'           => $result->upserted,
                'deleted'            => $result->deleted,
                'ignoredReflection'  => $result->ignoredReflection,
                'fullSync'           => $isFullSync,
            ],
        );

        return $result;
    }

    /**
     * @param array{items: list<array<string, mixed>>, nextPageToken: ?string, nextSyncToken: ?string} $page
     */
    private function ingestPage(array $page, int $accountId, PullResult $result): void
    {
        $utc = new DateTimeZone('UTC');
        foreach ($page['items'] as $ev) {
            $eventId = (string) $ev['id'];
            $status  = (string) ($ev['status'] ?? 'confirmed');

            if ($status === 'cancelled') {
                ($this->deleteBusyBlock)($accountId, $eventId);
                $result->deleted++;
                continue;
            }

            // Reflection check.
            $bookingId = ($this->findBookingByEventId)($eventId);
            if ($bookingId !== null) {
                $result->ignoredReflection++;
                continue;
            }

            $startStr = $ev['start'] ?? null;
            $endStr   = $ev['end']   ?? null;
            if (!is_string($startStr) || !is_string($endStr)) {
                $this->logEntry('warn', $accountId, 'pull_skip_no_datetime', 'failed', 'Event without dateTime', ['eventId' => $eventId]);
                continue;
            }

            $bb = BusyBlock::fromGoogleEvent(
                googleAccountId: $accountId,
                eventId: $eventId,
                start: new DateTimeImmutable($startStr),
                end:   new DateTimeImmutable($endStr),
                summary: (string) ($ev['summary'] ?? ''),
                syncedAt: new DateTimeImmutable('now', $utc),
            );
            ($this->upsertBusyBlock)($bb);
            $result->upserted++;
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logEntry(string $level, int $accountId, string $action, string $status, ?string $error, array $extra = []): void
    {
        ($this->log)([
            'level'           => $level,
            'direction'       => 'g_to_wp',
            'entity'          => 'busy_block',
            'entity_id'       => $accountId,
            'google_event_id' => null,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
            'payload'         => $extra,
        ]);
    }
}
```

- [ ] **Step 4 : Créer le DTO `PullResult` dans le même namespace**

Créer `src/Google/PullResult.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

final class PullResult
{
    public int $upserted = 0;
    public int $deleted = 0;
    public int $ignoredReflection = 0;
}
```

- [ ] **Step 5 : Lancer → vert**

```bash
composer test -- --filter SyncEngineTest
composer test && composer stan && composer cs
```

- [ ] **Step 6 : Commit**

```bash
git add src/Google/SyncEngine.php src/Google/PullResult.php tests/Unit/Google/SyncEngineTest.php
git commit -m "feat(google): SyncEngine pulls events.list + upserts busy blocks + handles 410"
```

---

## Task 12 : `Google\PullEventJob` — Action Scheduler handler `sb/google_pull`

Wrapper léger qui orchestre `WatchChannelManager` n'est pas nécessaire ; le job orchestre `GoogleClientBuilder` (pour le token refresh) + `SyncEngine::pull`.

**Files:**
- Create: `src/Google/PullEventJob.php`
- Create: `tests/Unit/Google/PullEventJobTest.php`

- [ ] **Step 1 : Écrire le test**

`tests/Unit/Google/PullEventJobTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\PullEventJob;
use Slash\Booking\Google\PullResult;
use Slash\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class PullEventJobTest extends TestCase
{
    public function test_handle_skips_if_account_missing(): void
    {
        $logged = [];
        $job = new PullEventJob(
            findAccount: fn () => null,
            buildGateway: fn () => new FakeCalendarGateway(),
            pull: fn () => new PullResult(),
            log: function (array $e) use (&$logged): void {
                $logged[] = $e;
            },
        );
        $job->handle(999);

        self::assertNotEmpty($logged);
        self::assertSame('failed', $logged[0]['status']);
        self::assertStringContainsString('account not found', $logged[0]['error_message']);
    }

    public function test_handle_invokes_pull_when_account_present(): void
    {
        $utc = new DateTimeZone('UTC');
        $account = GoogleAccount::connect('l', 'primary', 'r', 'a', new DateTimeImmutable('+1 hour', $utc));
        $account->assignId(7);

        $invoked = false;
        $job = new PullEventJob(
            findAccount: fn (int $id) => $id === 7 ? $account : null,
            buildGateway: fn () => new FakeCalendarGateway(),
            pull: function () use (&$invoked): PullResult {
                $invoked = true;
                return new PullResult();
            },
            log: fn () => null,
        );
        $job->handle(7);
        self::assertTrue($invoked);
    }
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter PullEventJobTest
```

- [ ] **Step 3 : Créer `src/Google/PullEventJob.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\GoogleApiError;
use Slash\Booking\Google\Exceptions\GoogleClientError;
use Slash\Booking\Google\Exceptions\OAuthFailure;

final class PullEventJob
{
    /**
     * @param Closure(int): ?GoogleAccount        $findAccount
     * @param Closure(GoogleAccount): CalendarGateway $buildGateway
     * @param Closure(GoogleAccount, CalendarGateway): PullResult $pull
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly Closure $findAccount,
        private readonly Closure $buildGateway,
        private readonly Closure $pull,
        private readonly Closure $log,
    ) {
    }

    public function handle(int $accountId): void
    {
        $account = ($this->findAccount)($accountId);
        if ($account === null) {
            $this->logEntry('error', $accountId, 'pull', 'failed', 'account not found');
            return;
        }

        try {
            $gateway = ($this->buildGateway)($account);
        } catch (OAuthFailure $e) {
            $this->logEntry('error', $accountId, 'pull', 'failed', 'token refresh failed: ' . $e->getMessage());
            return;
        }

        try {
            ($this->pull)($account, $gateway);
        } catch (GoogleClientError $e) {
            $this->logEntry('error', $accountId, 'pull', 'failed', '4xx: ' . $e->getMessage());
        } catch (GoogleApiError $e) {
            // Transient: let Action Scheduler retry (rethrow).
            $this->logEntry('warn', $accountId, 'pull', 'retry', '5xx/429: ' . $e->getMessage());
            throw $e;
        }
    }

    private function logEntry(string $level, int $accountId, string $action, string $status, ?string $error): void
    {
        ($this->log)([
            'level'           => $level,
            'direction'       => 'g_to_wp',
            'entity'          => 'busy_block',
            'entity_id'       => $accountId,
            'google_event_id' => null,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
            'payload'         => [],
        ]);
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test -- --filter PullEventJobTest
composer test && composer stan && composer cs
```

- [ ] **Step 5 : Commit**

```bash
git add src/Google/PullEventJob.php tests/Unit/Google/PullEventJobTest.php
git commit -m "feat(google): PullEventJob Action Scheduler handler"
```

---

## Task 13 : `Http\GoogleWebhookController` — POST /google/webhook

Endpoint public Google. Vérifie `X-Goog-Channel-Token`. Réponse 200 immédiate + enqueue async job.

**Files:**
- Create: `src/Http/GoogleWebhookController.php`
- Create: `tests/Integration/GoogleWebhookControllerTest.php`

- [ ] **Step 1 : Écrire le test d'intégration**

`tests/Integration/GoogleWebhookControllerTest.php` :

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Http\GoogleWebhookController;
use Slash\Booking\Persistence\GoogleAccountRepository;
use WP_REST_Request;

final class GoogleWebhookControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_REST_Request')) {
            self::markTestSkipped('WP REST not available.');
        }
    }

    private function freshAccount(): GoogleAccount
    {
        global $wpdb;
        // Clean slate
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DELETE FROM {$wpdb->prefix}sb_google_accounts");

        $repo = new GoogleAccountRepository($wpdb);
        $a = GoogleAccount::connect(
            'l',
            'primary',
            'r',
            'a',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        $a->attachWatch('ch_known', 'res_known', 'sec_known', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));
        $repo->save($a);
        return $a;
    }

    public function test_rejects_wrong_token(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'wrong');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(401, $resp->get_status());
        self::assertSame([], $enqueued);
    }

    public function test_accepts_valid_token_and_enqueues_pull(): void
    {
        $account = $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([(int) $account->id()], $enqueued);
    }

    public function test_sync_state_ack_no_pull(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'sync'); // Initial sync ack — no pull needed.

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([], $enqueued);
    }
}
```

- [ ] **Step 2 : Lancer → rouge ou skipped**

```bash
composer test:integration -- --filter GoogleWebhookControllerTest
```

- [ ] **Step 3 : Créer `src/Http/GoogleWebhookController.php`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Closure;
use Slash\Booking\Persistence\GoogleAccountRepository;
use Slash\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class GoogleWebhookController
{
    /**
     * @param Closure(int): void                  $enqueuePull
     * @param Closure(array<string, mixed>): void $log
     */
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly Closure $enqueuePull,
        private readonly Closure $log,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/google/webhook',
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true', // Public; auth via X-Goog-Channel-Token.
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $token        = (string) $request->get_header('X-Goog-Channel-Token');
        $channelId    = (string) $request->get_header('X-Goog-Channel-Id');
        $resourceState = (string) $request->get_header('X-Goog-Resource-State');

        $account = $this->accounts->findSingle();
        if ($account === null || !$account->verifyWatchToken($token)) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => null,
                'google_event_id' => null,
                'action'          => 'webhook_rejected',
                'status'          => 'failed',
                'error_message'   => 'token mismatch',
                'payload'         => ['channelId' => $channelId, 'state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => false], 401);
        }

        // Match Channel ID too (defense in depth: if multiple channels rotated, ensures we trigger pull for the right account).
        if ($account->watchChannelId() !== null && $account->watchChannelId() !== $channelId) {
            ($this->log)([
                'level'           => 'warn',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_stale_channel',
                'status'          => 'failed',
                'error_message'   => "received channelId={$channelId}, expected {$account->watchChannelId()}",
                'payload'         => ['state' => $resourceState],
            ]);
            return new WP_REST_Response(['ok' => true], 200); // Don't 4xx Google or it might disable our channel.
        }

        // Initial "sync" handshake — Google sends this once after channel creation. No pull needed.
        if ($resourceState === 'sync') {
            ($this->log)([
                'level'           => 'info',
                'direction'       => 'internal',
                'entity'          => 'watch',
                'entity_id'       => $account->id(),
                'google_event_id' => null,
                'action'          => 'webhook_sync_ack',
                'status'          => 'ok',
                'error_message'   => null,
                'payload'         => ['channelId' => $channelId],
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        ($this->enqueuePull)((int) $account->id());

        ($this->log)([
            'level'           => 'info',
            'direction'       => 'internal',
            'entity'          => 'watch',
            'entity_id'       => $account->id(),
            'google_event_id' => null,
            'action'          => 'webhook_received',
            'status'          => 'ok',
            'error_message'   => null,
            'payload'         => ['state' => $resourceState],
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test:integration -- --filter GoogleWebhookControllerTest
```

- [ ] **Step 5 : Vérifier**

```bash
composer test && composer stan && composer cs
```

- [ ] **Step 6 : Commit**

```bash
git add src/Http/GoogleWebhookController.php tests/Integration/GoogleWebhookControllerTest.php
git commit -m "feat(http): GoogleWebhookController — verify X-Goog-Channel-Token + enqueue pull"
```

---

## Task 14 : Étendre `AdminGoogleController` — start/stop watch + force pull

3 nouveaux endpoints admin pour piloter manuellement le watch + lancer un pull immédiat.

**Files:**
- Modify: `src/Http/AdminGoogleController.php`
- Modify: `tests/Integration/AdminGoogleControllerTest.php`

- [ ] **Step 1 : Lire la structure actuelle de `AdminGoogleController`**

```bash
grep -n "registerRoutes\|register_rest_route\|public function" src/Http/AdminGoogleController.php | head -30
```

Repérer le pattern d'enregistrement de routes (méthode `registerRoutes`).

- [ ] **Step 2 : Ajouter 4 endpoints dans `AdminGoogleController::registerRoutes()`**

Après les routes existantes, ajouter :

```php
        register_rest_route(\Slash\Booking\Plugin::REST_NAMESPACE, '/admin/google/watch/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'watchStart'],
            'permission_callback' => fn () => current_user_can('slashbooking_manage'),
        ]);
        register_rest_route(\Slash\Booking\Plugin::REST_NAMESPACE, '/admin/google/watch/stop', [
            'methods'             => 'POST',
            'callback'            => [$this, 'watchStop'],
            'permission_callback' => fn () => current_user_can('slashbooking_manage'),
        ]);
        register_rest_route(\Slash\Booking\Plugin::REST_NAMESPACE, '/admin/google/pull/now', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pullNow'],
            'permission_callback' => fn () => current_user_can('slashbooking_manage'),
        ]);
        register_rest_route(\Slash\Booking\Plugin::REST_NAMESPACE, '/admin/google/diagnostics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'diagnostics'],
            'permission_callback' => fn () => current_user_can('slashbooking_manage'),
        ]);
```

- [ ] **Step 3 : Injecter `WatchChannelManager`, `GoogleClientBuilder`, et un callback `enqueuePull` dans le ctor**

Modifier le ctor de `AdminGoogleController` pour accepter (en s'inspirant du ctor existant) :

```php
public function __construct(
    private readonly \Slash\Booking\Persistence\GoogleAccountRepository $accounts,
    private readonly \Slash\Booking\Google\OAuthClient $oauthClient,
    private readonly \Slash\Booking\Google\OAuthState $oauthState,
    private readonly \Slash\Booking\Google\Encryption $encryption,
    private readonly \Slash\Booking\Google\WatchChannelManager $watchManager,
    private readonly \Slash\Booking\Google\GoogleClientBuilder $clientBuilder,
    private readonly \Closure $enqueuePull,
) {
}
```

Adapter `RestRouter` (Task 18) en conséquence.

- [ ] **Step 4 : Implémenter les 4 handlers**

Ajouter dans `AdminGoogleController` :

```php
    public function watchStart(): \WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        try {
            $gateway = $this->clientBuilder->buildGateway($account);
            $webhookUrl = rest_url(\Slash\Booking\Plugin::REST_NAMESPACE . '/google/webhook');
            $this->watchManager->start($account, $gateway, $webhookUrl);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        return new \WP_REST_Response([
            'ok'         => true,
            'channelId'  => $account->watchChannelId(),
            'expiresAt'  => $account->watchExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function watchStop(): \WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        try {
            $gateway = $this->clientBuilder->buildGateway($account);
            $this->watchManager->stop($account, $gateway);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        return new \WP_REST_Response(['ok' => true]);
    }

    public function pullNow(): \WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'no_account'], 400);
        }
        ($this->enqueuePull)((int) $account->id());
        return new \WP_REST_Response(['ok' => true]);
    }

    public function diagnostics(): \WP_REST_Response
    {
        $account = $this->accounts->findSingle();
        if ($account === null) {
            return new \WP_REST_Response(['connected' => false]);
        }
        return new \WP_REST_Response([
            'connected'        => true,
            'label'            => $account->label(),
            'calendarId'       => $account->calendarId(),
            'tokenExpiresAt'   => $account->expiresAt()->format(\DateTimeInterface::ATOM),
            'watch'            => [
                'channelId'  => $account->watchChannelId(),
                'resourceId' => $account->watchResourceId(),
                'expiresAt'  => $account->watchExpiresAt()?->format(\DateTimeInterface::ATOM),
            ],
            'syncToken'        => $account->syncToken() !== null,
            'lastFullSyncAt'   => $account->lastFullSyncAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
```

- [ ] **Step 5 : Ajouter un test d'intégration**

Dans `tests/Integration/AdminGoogleControllerTest.php`, ajouter (en s'inspirant des tests existants pour authentifier l'admin) :

```php
public function test_diagnostics_returns_disconnected_if_no_account(): void
{
    wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    $req = new \WP_REST_Request('GET', '/slashbooking/v1/admin/google/diagnostics');
    $resp = rest_do_request($req);
    self::assertSame(200, $resp->get_status());
    self::assertSame(['connected' => false], $resp->get_data());
}
```

- [ ] **Step 6 : Lancer → vert (Task 18 finira de câbler `RestRouter`)**

À ce stade, `RestRouter` instancie encore `AdminGoogleController` avec l'ancienne signature → fail tests integration. C'est attendu : on commit le controller maintenant et on câble le router en Task 18.

```bash
composer test
composer stan
```

- [ ] **Step 7 : Commit**

```bash
git add src/Http/AdminGoogleController.php tests/Integration/AdminGoogleControllerTest.php
git commit -m "feat(http): AdminGoogleController watch start/stop + pull/now + diagnostics"
```

---

## Task 15 : Wiring complet dans `Plugin.php` (webhook, pull job, crons)

C'est la tâche d'assemblage. On enregistre :
- Le callback Action Scheduler `sb/google_pull` qui exécute `PullEventJob`.
- Le webhook controller dans `RestRouter` (cf. Task 18).
- Le cron quotidien `sb/watch_renew_check` qui renouvelle si proche expiration.
- Le cron 15 min `sb/google_pull_all` qui enfile un pull par compte.

**Files:**
- Modify: `src/Plugin.php`

- [ ] **Step 1 : Lire `src/Plugin.php` actuel**

Repérer la zone "Plan 3 Google sync" (lignes ~114-216). On va y ajouter le wiring Plan 4 juste après.

- [ ] **Step 2 : Ajouter les imports en haut du fichier**

Pas d'import nominal nécessaire — tout est utilisé via FQCN dans `register()`. Mais si tu préfères des `use` propres, ajouter en début de fichier après le namespace :

```php
use Slash\Booking\Google\PullEventJob;
use Slash\Booking\Google\PullResult;
use Slash\Booking\Google\SyncEngine;
use Slash\Booking\Google\WatchChannelManager;
use Slash\Booking\Persistence\BusyBlockRepository;
```

- [ ] **Step 3 : Ajouter le wiring Plan 4 après le bloc Plan 3 existant**

Juste avant `(new Admin\AdminMenu())->register();` dans `register()`, insérer :

```php
        // ----- Google sync inbound (Plan 4) -----
        $busyRepo = new Persistence\BusyBlockRepository($wpdb);
        $watchMgr = new Google\WatchChannelManager(
            persist: fn (Domain\GoogleAccount $a) => $accounts->save($a),
            ttlSeconds: 604_800, // 7 days
        );

        // SyncEngine factory closure (each pull builds a new instance with fresh closures).
        $buildSyncEngine = static function () use ($bookings, $busyRepo, $accounts, $syncLogRepo): Google\SyncEngine {
            return new Google\SyncEngine(
                findBookingByEventId: function (string $eventId) use ($bookings): ?int {
                    $b = $bookings->findByGoogleEventId($eventId);
                    return $b?->id();
                },
                upsertBusyBlock: fn (Domain\BusyBlock $bb) => $busyRepo->upsertFromGoogle($bb),
                deleteBusyBlock: fn (int $accountId, string $sourceId) => $busyRepo->deleteBySourceId($accountId, $sourceId),
                persistAccount: fn (Domain\GoogleAccount $a) => $accounts->save($a),
                log: function (array $entry) use ($syncLogRepo): void {
                    $syncLogRepo->append(
                        level: (string) $entry['level'],
                        direction: (string) $entry['direction'],
                        entity: (string) $entry['entity'],
                        entityId: $entry['entity_id'] !== null ? (int) $entry['entity_id'] : null,
                        googleEventId: $entry['google_event_id'] !== null ? (string) $entry['google_event_id'] : null,
                        action: (string) $entry['action'],
                        status: (string) $entry['status'],
                        payload: is_array($entry['payload'] ?? null) ? $entry['payload'] : [],
                        errorMessage: $entry['error_message'] !== null ? (string) $entry['error_message'] : null,
                    );
                },
            );
        };

        // Enqueue helper (used by webhook + cron fallback + admin "pull now").
        $enqueuePull = static function (int $accountId): void {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'sb/google_pull', [$accountId], 'slashbooking');
                return;
            }
            // Fallback: run synchronously (test / no AS loaded).
            do_action('sb/google_pull', $accountId);
        };

        // Action Scheduler handler.
        add_action('sb/google_pull', static function (int $accountId) use (
            $accounts,
            $clientBuilder,
            $buildSyncEngine,
            $syncLogRepo
        ): void {
            $job = new Google\PullEventJob(
                findAccount: fn (int $id) => $accounts->findById($id),
                buildGateway: fn (Domain\GoogleAccount $a) => $clientBuilder->buildGateway($a),
                pull: fn (Domain\GoogleAccount $a, Google\CalendarGateway $g) => $buildSyncEngine()->pull($a, $g),
                log: function (array $entry) use ($syncLogRepo): void {
                    $syncLogRepo->append(
                        level: (string) $entry['level'],
                        direction: (string) $entry['direction'],
                        entity: (string) $entry['entity'],
                        entityId: $entry['entity_id'] !== null ? (int) $entry['entity_id'] : null,
                        googleEventId: $entry['google_event_id'] !== null ? (string) $entry['google_event_id'] : null,
                        action: (string) $entry['action'],
                        status: (string) $entry['status'],
                        payload: is_array($entry['payload'] ?? null) ? $entry['payload'] : [],
                        errorMessage: $entry['error_message'] !== null ? (string) $entry['error_message'] : null,
                    );
                },
            );
            $job->handle($accountId);
        }, 10, 1);

        // Daily watch renewal check.
        add_action('sb/watch_renew_check', static function () use ($accounts, $clientBuilder, $watchMgr, $syncLogRepo): void {
            $account = $accounts->findSingle();
            if ($account === null) {
                return;
            }
            $expiresAt = $account->watchExpiresAt();
            $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $threshold = $now->modify('+1 day');
            if ($expiresAt !== null && $expiresAt > $threshold) {
                return; // Still > 24h before expiration.
            }
            try {
                $gateway = $clientBuilder->buildGateway($account);
                $webhookUrl = rest_url(Plugin::REST_NAMESPACE . '/google/webhook');
                $watchMgr->renew($account, $gateway, $webhookUrl);
                $syncLogRepo->append(
                    level: 'info', direction: 'internal', entity: 'watch',
                    entityId: $account->id(), googleEventId: null, action: 'watch_renewed',
                    status: 'ok', payload: ['channelId' => $account->watchChannelId()], errorMessage: null,
                );
            } catch (\Throwable $e) {
                $syncLogRepo->append(
                    level: 'error', direction: 'internal', entity: 'watch',
                    entityId: $account->id(), googleEventId: null, action: 'watch_renew',
                    status: 'failed', payload: [], errorMessage: $e->getMessage(),
                );
            }
        });

        // 15-min cron fallback: enqueue pull for each account.
        add_action('sb/google_pull_all', static function () use ($accounts, $enqueuePull): void {
            $account = $accounts->findSingle();
            if ($account === null) {
                return;
            }
            $enqueuePull((int) $account->id());
        });

        // Expose $watchMgr + $enqueuePull to RestRouter via the service container.
        $this->set(Google\WatchChannelManager::class, $watchMgr);
        $this->set('tb.enqueuePull', new class ($enqueuePull) {
            /** @var \Closure */
            public \Closure $callable;
            public function __construct(\Closure $c) { $this->callable = $c; }
        });
```

Note : on aurait pu créer une mini-classe DI proprement, mais Plugin.php utilise déjà un mini-registre `set/get` typé par class-string. Pour exposer une closure, on l'enveloppe dans un objet anonyme accessible via `get('tb.enqueuePull')->callable`. Le `RestRouter` (Task 18) le récupérera.

- [ ] **Step 4 : Vérifier**

```bash
composer test && composer stan
```

PHPStan peut râler sur l'objet anonyme + `class-string` typed container. Si oui : créer une classe nommée `Support\ClosureBox` dans `src/Support/` :

```php
<?php
declare(strict_types=1);
namespace Slash\Booking\Support;
final class ClosureBox
{
    public function __construct(public readonly \Closure $callable) {}
}
```

Et l'utiliser : `$this->set(\Slash\Booking\Support\ClosureBox::class, new ClosureBox($enqueuePull));`. Le `RestRouter` récupère via `$plugin->get(ClosureBox::class)->callable`.

- [ ] **Step 5 : Commit**

```bash
git add src/Plugin.php src/Support/ClosureBox.php 2>/dev/null
git commit -m "feat(plugin): wire SyncEngine + PullEventJob + watch renewal cron + pull-all cron"
```

---

## Task 16 : Câbler le `RestRouter` (webhook + endpoints admin Plan 4)

**Files:**
- Modify: `src/Http/RestRouter.php`

- [ ] **Step 1 : Lire le `RestRouter` actuel**

Repérer la création de `AdminGoogleController` (lignes ~68-77). On va lui passer les nouvelles dépendances + enregistrer `GoogleWebhookController`.

- [ ] **Step 2 : Modifier `src/Http/RestRouter.php`**

Remplacer la portion Plan 3 + ajouter Plan 4 :

```php
        $accounts    = new \Slash\Booking\Persistence\GoogleAccountRepository($wpdb);
        $keyResolver = new \Slash\Booking\Google\EncryptionKeyResolver();
        $encryption  = new \Slash\Booking\Google\Encryption($keyResolver->resolve());
        $oauthState  = new \Slash\Booking\Google\OAuthState((string) get_option('sb_decision_secret'));
        $oauthClient = new \Slash\Booking\Google\OAuthClient(
            clientId: (string) get_option('sb_google_client_id', ''),
            clientSecret: (string) get_option('sb_google_client_secret', ''),
            redirectUri: rest_url(\Slash\Booking\Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        );

        $clientBuilder = new \Slash\Booking\Google\GoogleClientBuilder($encryption, $accounts);
        $watchMgr      = new \Slash\Booking\Google\WatchChannelManager(
            persist: fn (\Slash\Booking\Domain\GoogleAccount $a) => $accounts->save($a),
            ttlSeconds: 604_800,
        );
        $enqueuePull = static function (int $accountId): void {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'sb/google_pull', [$accountId], 'slashbooking');
                return;
            }
            do_action('sb/google_pull', $accountId);
        };

        (new AdminGoogleController(
            $accounts,
            $oauthClient,
            $oauthState,
            $encryption,
            $watchMgr,
            $clientBuilder,
            $enqueuePull,
        ))->registerRoutes();

        $syncLog = new \Slash\Booking\Persistence\SyncLogRepository($wpdb);
        (new AdminSyncLogController($syncLog))->registerRoutes();

        (new AdminGoogleSettingsController())->registerRoutes();

        // Plan 4: public webhook.
        (new GoogleWebhookController(
            $accounts,
            $enqueuePull,
            log: function (array $entry) use ($syncLog): void {
                $syncLog->append(
                    level: (string) $entry['level'],
                    direction: (string) $entry['direction'],
                    entity: (string) $entry['entity'],
                    entityId: $entry['entity_id'] !== null ? (int) $entry['entity_id'] : null,
                    googleEventId: $entry['google_event_id'] !== null ? (string) $entry['google_event_id'] : null,
                    action: (string) $entry['action'],
                    status: (string) $entry['status'],
                    payload: is_array($entry['payload'] ?? null) ? $entry['payload'] : [],
                    errorMessage: $entry['error_message'] !== null ? (string) $entry['error_message'] : null,
                );
            },
        ))->registerRoutes();
```

Note : on duplique la création de `$enqueuePull` ici et dans `Plugin.php`. C'est OK — Plugin.php câble le **handler** Action Scheduler (`add_action('sb/google_pull')`), RestRouter câble le **producteur** (webhook + admin). Tous deux pointent vers le même hook AS. Si tu veux éviter la duplication : créer `Google\PullDispatcher` avec une méthode statique `enqueue(int $accountId)`. Optionnel — pour Plan 4 on reste sur la duplication.

- [ ] **Step 3 : Lancer la suite**

```bash
composer test && composer test:integration && composer stan && composer cs
```

Tout doit être vert (skipped propre acceptable).

- [ ] **Step 4 : Commit**

```bash
git add src/Http/RestRouter.php
git commit -m "feat(http): RestRouter wires webhook + admin watch/pull endpoints"
```

---

## Task 17 : `Activator` + `Deactivator` — schedule/unschedule crons + stop watch

**Files:**
- Modify: `src/Activator.php`
- Modify: `src/Deactivator.php`

- [ ] **Step 1 : Ajouter dans `Activator::activate()`** (juste après le `wp_schedule_event` du SyncLogPurger)

```php
        if (!wp_next_scheduled('sb/watch_renew_check')) {
            wp_schedule_event(self::tomorrowAt4SiteTz(), 'daily', 'sb/watch_renew_check');
        }

        // 15-minute cron interval — register if not present.
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['sb_fifteen_minutes'])) {
                $s['sb_fifteen_minutes'] = ['interval' => 900, 'display' => 'Every 15 minutes (SlashBooking)'];
            }
            return $s;
        });
        if (!wp_next_scheduled('sb/google_pull_all')) {
            wp_schedule_event(time() + 900, 'sb_fifteen_minutes', 'sb/google_pull_all');
        }
```

Et ajouter la méthode utilitaire :

```php
    private static function tomorrowAt4SiteTz(): int
    {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        return (new \DateTimeImmutable('tomorrow 04:00', $tz))->getTimestamp();
    }
```

Note : la `cron_schedules` filter doit être enregistrée **avant** que WP cherche `sb_fifteen_minutes`. À l'activation c'est le cas car on filtre + schedule dans la foulée. Mais il faut **aussi** ré-enregistrer ce filter à chaque boot du plugin sinon WP ne reconnaît plus l'intervalle. Ajouter dans `Plugin::register()` :

```php
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['sb_fifteen_minutes'])) {
                $s['sb_fifteen_minutes'] = ['interval' => 900, 'display' => 'Every 15 minutes (SlashBooking)'];
            }
            return $s;
        });
```

- [ ] **Step 2 : Modifier `src/Deactivator.php`**

```php
<?php

declare(strict_types=1);

namespace Slash\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        foreach ([
            \Slash\Booking\Notifications\ReminderScheduler::HOOK,
            \Slash\Booking\Google\SyncLogPurger::HOOK,
            'sb/watch_renew_check',
            'sb/google_pull_all',
        ] as $hook) {
            $ts = wp_next_scheduled($hook);
            if ($ts !== false) {
                wp_unschedule_event($ts, $hook);
            }
        }

        // Best-effort: stop watch channel via stored credentials.
        // We can't easily inject services here (static context). If the user reactivates,
        // a new watch will be created from the admin UI. The old channel will expire on its own
        // within 7 days. We log a soft note via error_log if available.
        if (function_exists('error_log')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[slashbooking] Deactivated. Active Google watch channel (if any) will expire within 7 days.');
        }
    }
}
```

Pourquoi pas de stop watch lors de la désactivation : l'API Google nécessite un client construit + un token valide ; lors de la désactivation WP, le bootstrap complet du plugin n'est pas garanti. Le channel expire de toute façon en ≤ 7 jours. L'admin peut explicitement "Stop watch" via l'UI **avant** la désactivation s'il veut être propre.

- [ ] **Step 3 : Vérifier**

```bash
composer test && composer test:integration && composer stan && composer cs
```

- [ ] **Step 4 : Commit**

```bash
git add src/Activator.php src/Deactivator.php src/Plugin.php
git commit -m "feat(activator): schedule sb/watch_renew_check + sb/google_pull_all crons"
```

---

## Task 18 : Étendre `wp slashbooking doctor` — watch status + last sync

**Files:**
- Modify: `src/Cli/DoctorCommand.php`
- Modify: `src/Plugin.php` (ajout des deps au ctor `DoctorCommand`)

- [ ] **Step 1 : Étendre le ctor de `DoctorCommand`**

Modifier le ctor pour accepter aussi `BusyBlockRepository` + une closure `pullNow`. Adapter `src/Plugin.php` au passage.

```php
public function __construct(
    private readonly GoogleAccountRepository $accounts,
    private readonly GoogleClientBuilder $clientBuilder,
    private readonly \Slash\Booking\Persistence\BusyBlockRepository $busy,
    private readonly \Closure $pullNow, // function(GoogleAccount): PullResult
) {
}
```

- [ ] **Step 2 : Ajouter à la fin de `__invoke`** :

```php
        // ----- Plan 4 probes -----
        \WP_CLI::log('---');
        if ($account->watchChannelId() === null) {
            \WP_CLI::warning('No watch channel registered. Run "Start watch" from admin UI.');
        } else {
            \WP_CLI::success(sprintf(
                'Watch channel: %s (expires=%s)',
                $account->watchChannelId(),
                $account->watchExpiresAt()?->format(\DateTimeInterface::ATOM) ?? 'unknown',
            ));
        }

        \WP_CLI::log('Performing test pull…');
        try {
            /** @var \Slash\Booking\Google\PullResult $result */
            $result = ($this->pullNow)($account);
            \WP_CLI::success(sprintf(
                'Pull OK: %d upserted / %d deleted / %d reflection-ignored',
                $result->upserted,
                $result->deleted,
                $result->ignoredReflection,
            ));
        } catch (\Throwable $e) {
            \WP_CLI::error('Pull failed: ' . $e->getMessage());
        }
```

- [ ] **Step 3 : Modifier le bloc CLI dans `Plugin.php`**

Remplacer :

```php
        if (defined('WP_CLI') && WP_CLI) {
            /** @phpstan-ignore-next-line Class.NotFound (WP_CLI conditionally available) */
            \WP_CLI::add_command('slashbooking doctor', new Cli\DoctorCommand(
                $accounts,
                $clientBuilder,
            ));
        }
```

par :

```php
        if (defined('WP_CLI') && WP_CLI) {
            $pullNow = static function (Domain\GoogleAccount $account) use ($clientBuilder, $buildSyncEngine): Google\PullResult {
                $gateway = $clientBuilder->buildGateway($account);
                return $buildSyncEngine()->pull($account, $gateway);
            };
            /** @phpstan-ignore-next-line Class.NotFound (WP_CLI conditionally available) */
            \WP_CLI::add_command('slashbooking doctor', new Cli\DoctorCommand(
                $accounts,
                $clientBuilder,
                $busyRepo,
                $pullNow,
            ));
        }
```

- [ ] **Step 4 : Vérifier**

```bash
composer test && composer stan && composer cs
```

(Pas de test unitaire facile pour DoctorCommand — il dépend de WP-CLI et `current_time`. Vérification = manuel sur WP.)

- [ ] **Step 5 : Commit**

```bash
git add src/Cli/DoctorCommand.php src/Plugin.php
git commit -m "feat(cli): doctor extends with watch status + test pull"
```

---

## Task 19 : SPA — étendre `GooglePage` avec watch + diagnostics

**Files:**
- Modify: `src/Admin/react-app/src/api.js`
- Modify: `src/Admin/react-app/src/GooglePage.jsx`

- [ ] **Step 1 : Ajouter les nouvelles fonctions dans `api.js`**

Ajouter à la fin de `src/Admin/react-app/src/api.js` :

```javascript
export async function fetchGoogleDiagnostics() {
	return apiFetch( { path: 'admin/google/diagnostics' } );
}

export async function startWatch() {
	return apiFetch( {
		path: 'admin/google/watch/start',
		method: 'POST',
	} );
}

export async function stopWatch() {
	return apiFetch( {
		path: 'admin/google/watch/stop',
		method: 'POST',
	} );
}

export async function forcePullNow() {
	return apiFetch( {
		path: 'admin/google/pull/now',
		method: 'POST',
	} );
}
```

- [ ] **Step 2 : Lire `GooglePage.jsx` actuel**

```bash
wc -l src/Admin/react-app/src/GooglePage.jsx
head -50 src/Admin/react-app/src/GooglePage.jsx
```

Identifier où ajouter le nouveau panel (probablement en bas, après "OAuth status").

- [ ] **Step 3 : Ajouter un panel "Sync entrante (webhook)" dans `GooglePage.jsx`**

Importer en haut :

```javascript
import {
	fetchGoogleDiagnostics,
	startWatch,
	stopWatch,
	forcePullNow,
} from './api';
```

Ajouter dans le composant (logique d'état + JSX) :

```javascript
const [ diag, setDiag ] = useState( null );
const [ busy, setBusy ] = useState( false );
const [ msg, setMsg ] = useState( '' );

const refresh = async () => {
	try {
		setDiag( await fetchGoogleDiagnostics() );
	} catch ( e ) {
		setMsg( 'Erreur de chargement diagnostics : ' + e.message );
	}
};

useEffect( () => {
	refresh();
}, [] );

const onStartWatch = async () => {
	setBusy( true );
	setMsg( '' );
	try {
		const r = await startWatch();
		setMsg( `Watch activé. Channel: ${ r.channelId } (expire ${ r.expiresAt })` );
		await refresh();
	} catch ( e ) {
		setMsg( 'Erreur : ' + e.message );
	} finally {
		setBusy( false );
	}
};

const onStopWatch = async () => {
	setBusy( true );
	try {
		await stopWatch();
		setMsg( 'Watch arrêté.' );
		await refresh();
	} catch ( e ) {
		setMsg( 'Erreur : ' + e.message );
	} finally {
		setBusy( false );
	}
};

const onPullNow = async () => {
	setBusy( true );
	try {
		await forcePullNow();
		setMsg( 'Pull enfilé. Vérifie le Journal dans quelques secondes.' );
	} catch ( e ) {
		setMsg( 'Erreur : ' + e.message );
	} finally {
		setBusy( false );
	}
};
```

JSX (à insérer dans le rendu) :

```jsx
<Panel>
	<PanelBody title={ __( 'Synchronisation entrante (Google → WP)', 'slashbooking' ) } initialOpen>
		{ diag === null && <Spinner /> }
		{ diag !== null && diag.connected === false && (
			<p>{ __( 'Connectez d\'abord un compte Google ci-dessus.', 'slashbooking' ) }</p>
		) }
		{ diag !== null && diag.connected === true && (
			<>
				<p>
					<strong>{ __( 'Watch channel : ', 'slashbooking' ) }</strong>
					{ diag.watch.channelId
						? `${ diag.watch.channelId } (expire ${ diag.watch.expiresAt })`
						: __( 'aucun', 'slashbooking' ) }
				</p>
				<p>
					<strong>{ __( 'Dernier full sync : ', 'slashbooking' ) }</strong>
					{ diag.lastFullSyncAt ?? __( 'jamais', 'slashbooking' ) }
				</p>
				<p>
					<strong>{ __( 'Sync token : ', 'slashbooking' ) }</strong>
					{ diag.syncToken ? __( 'présent (sync incrémental actif)', 'slashbooking' ) : __( 'absent (prochain pull = full sync)', 'slashbooking' ) }
				</p>
				<HStack>
					{ ! diag.watch.channelId && (
						<Button variant="primary" onClick={ onStartWatch } disabled={ busy }>
							{ __( 'Démarrer le watch', 'slashbooking' ) }
						</Button>
					) }
					{ diag.watch.channelId && (
						<Button variant="secondary" isDestructive onClick={ onStopWatch } disabled={ busy }>
							{ __( 'Arrêter le watch', 'slashbooking' ) }
						</Button>
					) }
					<Button variant="tertiary" onClick={ onPullNow } disabled={ busy }>
						{ __( 'Forcer un pull maintenant', 'slashbooking' ) }
					</Button>
				</HStack>
				{ msg && <Notice status="info" isDismissible={ false }>{ msg }</Notice> }
			</>
		) }
	</PanelBody>
</Panel>
```

Imports à ajouter pour ce JSX (s'ils ne sont pas déjà là) :

```javascript
import { useState, useEffect } from 'react';
import { Panel, PanelBody, Button, Notice, Spinner } from '@wordpress/components';
// HStack vient de '@wordpress/components/build-module/ui/h-stack' OU est ré-exporté ; si import indispo, remplacer par <div style={{display:'flex', gap:'8px'}}>.
```

- [ ] **Step 4 : Build**

```bash
npm install --no-audit --no-fund
npm run build
```

Attendu : `assets/dist/admin.js`, `admin.css` produits sans erreur.

- [ ] **Step 5 : Lint**

```bash
npm run lint:js
```

(Si pas de script lint:js : ignorer.)

- [ ] **Step 6 : Commit**

```bash
git add src/Admin/react-app/src/api.js src/Admin/react-app/src/GooglePage.jsx
git commit -m "feat(admin-spa): GooglePage panel for watch start/stop + force pull + diagnostics"
```

---

## Task 20 : Tests integration finaux + suite complète

Vérification globale avant docs.

**Files:** aucun nouveau

- [ ] **Step 1 : Lancer toute la suite**

```bash
composer test
composer test:integration
composer stan
composer cs
npm run build
```

Attendu : tout vert (skipped propre OK si wp-phpunit absent). Si erreur :

- PHPStan sur les FQCN longs dans `Plugin.php` → ajouter `use` en haut du fichier.
- PHPCS sur les Closures → vérifier qu'on a pas oublié les annotations `// phpcs:ignore` sur les `$wpdb->insert` dans les nouveaux tests.
- Tests unit cassés → re-vérifier que les signatures des Closures injectées correspondent (un test casse souvent par confusion `?int $bookingId` vs `int`).

- [ ] **Step 2 : Vérifier le couverture spec**

Re-lire la section 6.5 et 6.6 du spec et cocher mentalement :

- [ ] Webhook reçoit POST + vérifie X-Goog-Channel-Token (Task 13) ✓
- [ ] Réponse 200 immédiate (Task 13) ✓
- [ ] Job sb/google_pull enfilé (Task 12 + 15) ✓
- [ ] events.list avec syncToken (Task 11) ✓
- [ ] Reflection ignored (Task 9 + 11) ✓
- [ ] Upsert BusyBlock pour event externe (Task 6 + 11) ✓
- [ ] Delete BusyBlock pour event cancelled (Task 6 + 11) ✓
- [ ] Update sync_token (Task 7 + 11) ✓
- [ ] Cron fallback 15 min (Task 15 + 17) ✓
- [ ] Watch renewal cron (Task 15 + 17) ✓
- [ ] 410 Gone → reset sync_token + full sync (Task 11) ✓

- [ ] **Step 3 : Commit final (vide si rien à committer)**

Si la passe a révélé des fixes mineurs :

```bash
git add -p
git commit -m "fix: address review findings from Plan 4 final pass"
```

Sinon, passer à la Task 21.

---

## Task 21 : Documentation README + diagnostics

**Files:**
- Modify: `README.md`

- [ ] **Step 1 : Ajouter dans `README.md` une section "Sync entrante Google → WP"**

Avant la section "Diagnostics" existante :

````markdown
## Sync entrante Google → WP (Plan 4)

Un événement créé directement dans Google Calendar devient automatiquement un `BusyBlock` côté WP en ~5 secondes (via webhook) ou ≤ 15 minutes (cron fallback).

### Mécanisme

1. **Watch channel.** Une fois connecté, l'admin ouvre l'onglet **Google** et clique **Démarrer le watch**. Un channel push notifications est créé chez Google (TTL 7 jours). À chaque modif de calendrier, Google POST notre webhook public.
2. **Webhook.** `POST /wp-json/slashbooking/v1/google/webhook`. Vérification HMAC du header `X-Goog-Channel-Token` → enfile un job `sb/google_pull` debounced (5 s).
3. **Pull job.** Action Scheduler exécute `sb/google_pull` qui appelle `events.list?syncToken=…` pour récupérer les diffs incrémentaux. Upsert / delete des `BusyBlock` selon `status` de l'event.
4. **Reflection.** Quand notre push (Plan 3) crée un event GCal, Google nous re-notifie. On l'ignore (lookup `google_event_id` dans `wp_sb_bookings`).
5. **Renewal.** Cron quotidien `sb/watch_renew_check` : si le channel expire dans < 24h, on le renouvelle.
6. **Fallback.** Cron `sb/google_pull_all` toutes les 15 min : exécute un pull même si le webhook n'a rien reçu (firewall, DNS, etc.).

### Pré-requis Google Cloud

- Webhook URL **doit** être HTTPS et publique. Google rejette `http://` et les IPs RFC1918.
- En dev local : utiliser un tunnel **ngrok** (`ngrok http 8080`) ou **Cloudflare Tunnel** et configurer `WP_HOME` / `WP_SITEURL` sur l'URL publique le temps des tests.

### Diagnostics

- **WP admin → SlashBooking → Google** : statut watch (channel id, expires_at), dernier full sync, présence du sync token, boutons "Démarrer/Arrêter watch" et "Forcer un pull".
- **WP-CLI** : `wp slashbooking doctor` — vérifie OAuth, watch, et lance un pull de test.
- **Journal** : SlashBooking → Journal, filtre `direction=g_to_wp` ou `entity=watch`.

### Désactivation propre

Avant de désactiver le plugin, l'admin **doit** cliquer **Arrêter le watch** pour libérer le channel côté Google. Sinon le channel expire de lui-même en ≤ 7 jours.
````

- [ ] **Step 2 : Vérifier le rendu Markdown**

```bash
head -100 README.md
```

- [ ] **Step 3 : Commit**

```bash
git add README.md
git commit -m "docs: document inbound Google sync (webhook + watch + pull)"
```

---

## Task 22 : Mise à jour mémoire + Plan 4 complete

**Files:** mémoire utilisateur (`/Users/seraphin/.claude/projects/.../memory/`)

- [ ] **Step 1 : Mettre à jour `project_overview.md`**

Table des plans :

```
| 4    | ✅ **Terminé YYYY-MM-DD** | Webhook Google + pull GCal→WP (SyncEngine + WatchChannelManager + crons renouvellement & fallback) |
```

(remplacer YYYY-MM-DD par la date du jour)

- [ ] **Step 2 : Créer `project_post_plan4_actions.md`**

Documenter :

- État du repo (commits Plan 4, tests verts, PHPStan 2.x).
- Tests manuels avant Plan 5 : configurer un tunnel HTTPS, démarrer le watch, créer un event dans GCal, vérifier l'apparition d'un BusyBlock.
- Plan 5 à écrire : Templates editor (CodeMirror + preview), RGPD exporters/erasers, i18n complet (.pot/.po), PHP-Scoper packaging, ZIP release.

- [ ] **Step 3 : Mettre à jour `MEMORY.md`**

Ajouter sous **Project** :

```
- [Action items post Plan 4](project_post_plan4_actions.md) — tests manuels webhook + tunnel HTTPS ; Plan 5 à écrire (templates editor + RGPD + i18n + packaging)
```

Et marquer `project_post_plan3_actions.md` comme obsolète OU le mettre à jour pour pointer vers Plan 4 complete.

- [ ] **Step 4 : Commit final**

```bash
git add README.md  # (si pas déjà commité par Task 21)
git commit --allow-empty -m "docs: mark Plan 4 complete"
```

---

## Definition of Done — Plan 4

- PHPStan **2.x** : 0 erreur (niveau 8 maintenu, `phpstan-wordpress ^2.0`).
- Tous tests unit + integration verts (skip propre si wp-phpunit absent).
- PHPCS : 0 erreur.
- `npm run build` produit l'`admin.{js,css,asset.php}` sans erreur.
- Manuel : créer un event directement dans Google Calendar → un `BusyBlock` apparaît dans la table `wp_sb_busy_blocks` ; le webhook log `webhook_received` puis `pull` ok dans `wp_sb_sync_log`.
- Manuel : `wp slashbooking doctor` affiche le statut watch + "Pull OK: N upserted / M deleted".
- Manuel : depuis l'onglet Google, "Démarrer watch" puis "Arrêter watch" fonctionnent.
- Manuel : forcer l'expiration `UPDATE wp_sb_google_accounts SET watch_expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)` puis trigger cron → channel renouvelé.
- README documente la procédure tunnel HTTPS + désactivation propre.

---

## Quickstart (V1 après Plan 4)

```bash
composer install
npm install && npm run build
composer test && composer test:integration && composer stan
```

Activer le plugin, configurer OAuth (Plan 3), démarrer le watch depuis l'onglet Google (Plan 4), créer un event dans Google Calendar → `BusyBlock` apparaît côté WP. Cycle complet bidirectionnel.

---

## Self-review (effectué par l'auteur du plan)

**Spec coverage :**

- §5 (`wp_sb_busy_blocks` index `(google_account_id, source_id)`, `last_synced_at`) → Task 6 ✓
- §5 (`wp_sb_google_accounts.watch_resource_id / watch_token_secret / last_full_sync_at`) → fix latent Plan 3 en Task 8 ✓
- §6.5.2 (header `X-Goog-Channel-Token` vérifié) → Task 13 ✓
- §6.5.3 (réponse 200 immédiate + push job) → Task 13 ✓
- §6.5.4 (events.list + syncToken, upsert BusyBlock, ignore reflection, delete sur cancelled) → Tasks 11, 6, 9 ✓
- §6.5.4 (update sync_token) → Tasks 7 + 11 ✓
- §6.5.5 (cron 15 min fallback) → Tasks 15, 17 ✓
- §6.6 (job watch renewal 6 jours, en réalité quotidien check, expiration < 24h trigger) → Tasks 15, 17 ✓
- §7 (REST `/google/webhook`) → Task 13 ✓
- §9 (token webhook vérifié) → Tasks 7 (`verifyWatchToken`), 13 ✓
- §12 (Diagnostics : watch id/expires + dernier sync) → Tasks 14 (`/admin/google/diagnostics`), 18 (doctor), 19 (SPA) ✓
- §15 (mitigation webhook non-reçu = cron 15 min + diag CLI) → Tasks 15, 17, 18 ✓

**Placeholder scan :** chaque step contient du code complet ou une commande shell exacte. Pas de "TBD" ni "implement later". Le commentaire "TODO" sur `stopChannel` lors de la désactivation est explicité (rationale : pas de bootstrap garanti) → conscient, pas un placeholder.

**Type consistency :**

- `CalendarGateway::listEvents/watchChannel/stopChannel` — signatures identiques dans interface (Task 2), `GoogleApiCalendarGateway` (Task 3), `FakeCalendarGateway` (Task 4), consommateurs (Tasks 10, 11, 14).
- `BusyBlock::fromGoogleEvent(int $googleAccountId, string $eventId, DateTimeImmutable $start, DateTimeImmutable $end, string $summary, ?DateTimeImmutable $syncedAt = null)` — utilisé identiquement par SyncEngine (Task 11) et tests (Tasks 5, 6).
- `GoogleAccount::attachWatch(channelId, resourceId, tokenSecret, expiresAt)` — utilisé par WatchChannelManager::start (Task 10) et tests Domain (Task 7).
- `PullEventJob::handle(int $accountId)` (et non `int $bookingId` comme PushEventJob) — pris comme `sb/google_pull` arg.
- `sb/google_pull` (singulier, account id) vs `sb/google_pull_all` (cron, sans args) — distinction nette.
- `SyncEngine::pull(GoogleAccount, CalendarGateway): PullResult` — signature unique.
- `WatchChannelManager` ctor `(Closure $persist, int $ttlSeconds)` — utilisé identiquement dans tests (Task 10) et Plugin.php (Task 15) / RestRouter (Task 16).
- `enqueuePull(int $accountId): void` — même type Closure dans `GoogleWebhookController`, `AdminGoogleController::pullNow`, `Plugin::register` (cron handler), et le helper static dans `RestRouter`.

**Hooks WP nommés :**

- `sb/google_pull` (Action Scheduler async, args: `[accountId]`) — producteur : webhook + admin pull/now + cron 15 min ; handler : `Plugin::register` add_action.
- `sb/google_pull_all` (cron 15 min, sans args) — handler : `Plugin::register` add_action ; enfile `sb/google_pull` par compte.
- `sb/watch_renew_check` (cron quotidien, sans args) — handler : `Plugin::register` add_action ; renouvelle si `watch_expires_at < now+1day`.
- `cron_schedules` (filter) — enregistre l'intervalle `sb_fifteen_minutes` ; appelé à l'activation **et** à chaque boot du plugin.

**Préreq tooling :**

- Task 1 = upgrade PHPStan 2.x **avant** d'écrire du code Google. Si l'upgrade casse plus que 30 min de fixes → on garde 1.x et on documente dans `phpstan.neon` (mais d'après la mémoire `project_post_plan3_actions`, ça doit passer proprement vu le code typé strict du Plan 3).

**Repoussé au Plan 5 :**

- PHP-Scoper / Mozart (toujours).
- RGPD exporters/erasers.
- Templates editor CodeMirror.
- i18n complet (.pot/.po).
- Packaging ZIP final.
