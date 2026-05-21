# slashbooking — Plan 2 : Notifications e-mail & validation admin

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner au plugin de quoi notifier client + admin par e-mail à chaque transition de booking, permettre à l'admin de confirmer/refuser un RDV en un clic depuis l'e-mail (URL signée HMAC) **et** depuis un dashboard React, et envoyer un rappel J-1 au client.

**Architecture:** On greffe sur les fondations du Plan 1. Trois nouveaux modules :
- `Notifications/` — render de templates `{{tags}}`, génération `.ics`, dispatch via `wp_mail`, registre de templates persistés.
- `Admin/` — capabilities custom, menu WP, enqueue d'une SPA React (`@wordpress/scripts`), bootstrap minimal du dashboard (liste des bookings + actions).
- Nouveaux use cases `ConfirmBooking` / `RejectBooking` qui se branchent sur les e-mails via un `BookingNotifier` listening aux hooks internes (`slashbooking/booking_created`, `confirmed`, `rejected`, `cancelled`, `reminder_due`).

Les e-mails sont déclenchés **synchronement** côté V1 (pas d'Action Scheduler dans ce plan — on s'appuie sur `wp_mail` et un cron WP classique pour le rappel J-1). Action Scheduler sera intégré en Plan 3 quand on poussera vers Google Calendar.

**Tech Stack:** Mêmes outils que Plan 1 + `@wordpress/scripts` (webpack préconfiguré WP), `@wordpress/components`, `@wordpress/api-fetch`, React 18 (fourni par WP 6.5+).

**Spec source:** `docs/superpowers/specs/2026-05-19-slashbooking-design.md`, sections 6.2, 6.3, 6.7, 7, 8, 9.

---

## Préambule — Concepts clés pour l'ingénieur

Lire avant d'attaquer les tâches.

1. **`wp_mail` peut échouer silencieusement.** On loggue chaque envoi (succès et échec) via `error_log` en V1 ; un canal dédié arrivera en Plan 4. Ne **jamais** faire planter une transition de booking parce qu'un e-mail n'est pas parti — on capture l'exception et on continue.

2. **HTML mail = headers + body.** Header `Content-Type: text/html; charset=UTF-8` + version texte auto-générée pour la délivrabilité. WP gère le multipart via le filtre `wp_mail_content_type`. On encapsule ça dans `MailDispatcher::send()` plutôt que de l'éparpiller.

3. **Template `{{tag}}` parser.** Implementation naïve `preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', ...)`. Tag inconnu = laisse `{{tag}}` brut et loggue en warn. Toutes les valeurs sont **escapées HTML par défaut** sauf si le tag est dans la liste `RAW_TAGS` (URLs déjà escapées, blocs HTML).

4. **`.ics` (iCalendar / RFC 5545).** Format texte UTF-8, lignes en CRLF. `DTSTART:20260601T120000Z` (UTC). On joint le fichier au mail via `wp_mail($attachments)`. Gestion CRLF + escape virgule/point-virgule/backslash dans `SUMMARY`/`DESCRIPTION`.

5. **HMAC URLs admin.** `?booking={id}&action={confirm|reject}&exp={unix}&sig={hmac}`. Payload signé = `"decide|{id}|{action}|{exp}"`. Expiration **72h**. Idempotent : si on rejoue la même requête, on retombe sur le même statut sans erreur (404 si déjà dans un autre statut terminal).

6. **Idempotence des transitions.** `confirm()` est appelable sur un booking déjà `confirmed` → no-op (return early), pas d'exception. Idem pour `reject()`. **Mais** depuis un statut incompatible (`cancelled` → `confirm`), on renvoie une page d'erreur dédiée, pas un 500.

7. **Capabilities WP custom.** Deux nouvelles caps : `slashbooking_manage` (admin par défaut, peut tout faire) et `slashbooking_view` (lecture). Seed à l'activation, retrait à la désinstallation. Le check se fait dans `permission_callback` des routes admin.

8. **React Admin SPA.** On utilise `@wordpress/scripts` qui fournit webpack + Babel + ESLint pré-configurés. Source dans `src/Admin/react-app/`. Build vers `assets/dist/admin.{js,css}`. Enqueue depuis `Admin\Assets`. WP injecte React/ReactDOM globalement → on les déclare en `dependencies` du wp_register_script (`'wp-element'`).

9. **Tests d'e-mails sans envoyer.** En unit on teste `TemplateRenderer`, `IcsBuilder`, `TagRegistry` sans toucher WP. En intégration on **intercepte** `wp_mail` via `add_filter('pre_wp_mail', ...)` (filtre WP 6.5+) et on inspecte le payload.

10. **Reminder J-1.** WP-Cron quotidien `sb_send_daily_reminders` à 10h00 fuseau site. Sélectionne `confirmed` dont `starts_at_utc` ∈ [now+23h, now+25h] avec `reminder_sent_at IS NULL`. Marque envoyé avant l'envoi (anti-doublon en cas de re-déclenchement cron).

---

## File Structure (Plan 2 scope)

```
plugins-booking/
├── package.json                                # NEW — @wordpress/scripts build
├── package-lock.json                           # NEW — committed
├── slashbooking.php                          # MODIFY — load services
├── src/
│   ├── Plugin.php                               # MODIFY — wire new services + register actions
│   ├── Activator.php                            # MODIFY — seed caps + templates
│   ├── Deactivator.php                          # MODIFY — unschedule cron
│   ├── Booking/
│   │   ├── ConfirmBooking.php                   # NEW use case
│   │   └── RejectBooking.php                    # NEW use case
│   ├── Notifications/
│   │   ├── TagRegistry.php                      # NEW — list/categories + raw tags
│   │   ├── TemplateRenderer.php                 # NEW — {{tag}} parser
│   │   ├── TextBodyGenerator.php                # NEW — HTML → plain text fallback
│   │   ├── IcsBuilder.php                       # NEW — RFC 5545
│   │   ├── DefaultTemplates.php                 # NEW — seeded HTML templates
│   │   ├── MailDispatcher.php                   # NEW — wp_mail wrapper
│   │   ├── BookingNotifier.php                  # NEW — hooks → MailDispatcher
│   │   ├── ReminderScheduler.php                # NEW — daily cron
│   │   └── Events/
│   │       ├── EventKey.php                     # NEW — enum
│   │       └── BookingContext.php               # NEW — dataset DTO
│   ├── Persistence/
│   │   └── MailTemplateRepository.php           # NEW
│   ├── Http/
│   │   ├── DecisionController.php               # NEW — GET /decide
│   │   ├── AdminBookingController.php           # NEW — REST admin
│   │   ├── RestRouter.php                       # MODIFY — register new controllers
│   │   └── UrlBuilder.php                       # NEW — helper to build signed URLs
│   ├── Admin/
│   │   ├── Capabilities.php                     # NEW — seed/remove caps
│   │   ├── AdminMenu.php                        # NEW — register WP menu
│   │   ├── Assets.php                           # NEW — enqueue admin SPA
│   │   └── react-app/
│   │       ├── src/
│   │       │   ├── index.jsx
│   │       │   ├── App.jsx
│   │       │   ├── api.js
│   │       │   ├── BookingsPage.jsx
│   │       │   ├── BookingRow.jsx
│   │       │   └── styles.scss
│   │       └── README.md
├── assets/dist/                                 # build output (gitignored)
├── tests/
│   ├── Unit/
│   │   ├── Booking/
│   │   │   ├── ConfirmBookingTest.php
│   │   │   └── RejectBookingTest.php
│   │   └── Notifications/
│   │       ├── TagRegistryTest.php
│   │       ├── TemplateRendererTest.php
│   │       ├── TextBodyGeneratorTest.php
│   │       ├── IcsBuilderTest.php
│   │       └── DefaultTemplatesTest.php
│   └── Integration/
│       ├── MailTemplateRepositoryTest.php
│       ├── DecisionControllerTest.php
│       ├── AdminBookingControllerTest.php
│       ├── BookingNotifierTest.php
│       └── ReminderSchedulerTest.php
```

---

## Workflow par tâche

1. Lire entièrement la tâche.
2. Écrire le(s) test(s) en premier.
3. Lancer → rouge avec le bon message d'erreur.
4. Implémenter minimal.
5. Lancer → vert.
6. Lancer la suite complète (`composer test`) pour ne rien casser.
7. PHPStan + PHPCS si la tâche touche `src/`.
8. Commit conventional.

---

## Task 1 : Domain — `Booking::confirm()` et `reject()` idempotents

**Files:**
- Modify: `src/Domain/Booking.php` (méthodes `confirm`, `reject`)
- Modify: `tests/Unit/Domain/BookingTest.php`

Justification : la spec exige l'idempotence (double-clic admin) — aujourd'hui `confirm()` jette `DomainException` si déjà confirmé. On rend l'opération idempotente quand on retombe sur le même statut cible.

- [ ] **Step 1 : Écrire les tests d'idempotence**

Ajouter dans `tests/Unit/Domain/BookingTest.php` :

```php
public function test_confirm_is_idempotent_when_already_confirmed(): void
{
    $b = $this->pendingBooking();
    $b->confirm();
    $b->confirm(); // ne doit pas lever
    self::assertSame(BookingStatus::CONFIRMED, $b->status());
}

public function test_reject_is_idempotent_when_already_rejected(): void
{
    $b = $this->pendingBooking();
    $b->reject();
    $b->reject();
    self::assertSame(BookingStatus::REJECTED, $b->status());
}

public function test_confirm_throws_from_cancelled(): void
{
    $b = $this->pendingBooking();
    $b->cancel();
    $this->expectException(\DomainException::class);
    $b->confirm();
}
```

- [ ] **Step 2 : Lancer → rouge**

```bash
composer test -- --filter BookingTest
```
Attendu : 3 tests rouges (DomainException jetée par idempotence ou méthode helper absente).

- [ ] **Step 3 : Si la méthode helper `pendingBooking()` n'existe pas dans le test, l'ajouter**

```php
private function pendingBooking(): \Slash\Booking\Domain\Booking
{
    $slot = new \Slash\Booking\Domain\TimeSlot(
        new \DateTimeImmutable('2026-06-01T08:00:00Z', new \DateTimeZone('UTC')),
        new \DateTimeImmutable('2026-06-01T09:30:00Z', new \DateTimeZone('UTC')),
    );
    return \Slash\Booking\Domain\Booking::createPending(
        serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
        customerName: 'Jean', customerEmail: 'j@x.fr',
        customerPhone: '0600', customerAddress: 'x',
        customerMeta: [], notes: '',
    );
}
```

- [ ] **Step 4 : Implémenter l'idempotence dans `Booking::confirm()` et `reject()`**

```php
public function confirm(): void
{
    if ($this->status === BookingStatus::CONFIRMED) {
        return; // idempotent
    }
    $this->mustBe(BookingStatus::PENDING, 'confirm');
    $this->status = BookingStatus::CONFIRMED;
    $this->touch();
}

public function reject(): void
{
    if ($this->status === BookingStatus::REJECTED) {
        return; // idempotent
    }
    $this->mustBe(BookingStatus::PENDING, 'reject');
    $this->status = BookingStatus::REJECTED;
    $this->touch();
}
```

- [ ] **Step 5 : Lancer → vert**

```bash
composer test
```
Attendu : suite verte (les 33 anciens + 3 nouveaux).

- [ ] **Step 6 : Commit**

```bash
git add src/Domain/Booking.php tests/Unit/Domain/BookingTest.php
git commit -m "feat(domain): make Booking::confirm/reject idempotent"
```

---

## Task 2 : Use case `ConfirmBooking`

**Files:**
- Create: `src/Booking/ConfirmBooking.php`
- Create: `tests/Unit/Booking/ConfirmBookingTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\ConfirmBooking;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;

final class ConfirmBookingTest extends TestCase
{
    private function pending(): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        return Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
    }

    public function test_confirms_pending_booking_and_persists(): void
    {
        $b = $this->pending();
        $b->assignId(42);

        $saved = false;
        $useCase = new ConfirmBooking(
            find: fn (int $id) => $id === 42 ? $b : null,
            persist: function (Booking $bb) use (&$saved): void { $saved = true; },
        );

        $useCase->execute(42);

        self::assertTrue($saved);
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_is_idempotent_on_already_confirmed(): void
    {
        $b = $this->pending();
        $b->assignId(42);
        $b->confirm();

        $useCase = new ConfirmBooking(
            find: fn () => $b,
            persist: fn () => null,
        );

        $useCase->execute(42); // doit ne pas lever
        self::assertSame(BookingStatus::CONFIRMED, $b->status());
    }

    public function test_throws_when_booking_missing(): void
    {
        $useCase = new ConfirmBooking(
            find: fn () => null,
            persist: fn () => null,
        );
        $this->expectException(BookingNotFound::class);
        $useCase->execute(999);
    }
}
```

- [ ] **Step 2 : Lancer → rouge (classe manquante)**

```bash
composer test -- --filter ConfirmBookingTest
```

- [ ] **Step 3 : Implémenter `ConfirmBooking`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Booking;

use Closure;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;

final class ConfirmBooking
{
    /**
     * @param Closure(int): ?Booking $find
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $find,
        private readonly Closure $persist,
    ) {
    }

    public function execute(int $bookingId): Booking
    {
        $booking = ($this->find)($bookingId);
        if ($booking === null) {
            throw new BookingNotFound("Booking {$bookingId} not found.");
        }
        $booking->confirm();
        ($this->persist)($booking);
        return $booking;
    }
}
```

- [ ] **Step 4 : Lancer → vert**

```bash
composer test
```

- [ ] **Step 5 : PHPStan**

```bash
composer stan
```
Attendu : 0 erreur.

- [ ] **Step 6 : Commit**

```bash
git add src/Booking/ConfirmBooking.php tests/Unit/Booking/ConfirmBookingTest.php
git commit -m "feat(booking): add ConfirmBooking use case"
```

---

## Task 3 : Use case `RejectBooking`

**Files:**
- Create: `src/Booking/RejectBooking.php`
- Create: `tests/Unit/Booking/RejectBookingTest.php`

- [ ] **Step 1 : Test miroir de ConfirmBooking**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Booking\RejectBooking;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;

final class RejectBookingTest extends TestCase
{
    private function pending(): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        return Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
    }

    public function test_rejects_pending_booking_and_persists(): void
    {
        $b = $this->pending();
        $b->assignId(7);
        $saved = false;
        $useCase = new RejectBooking(
            find: fn () => $b,
            persist: function () use (&$saved): void { $saved = true; },
        );
        $useCase->execute(7);
        self::assertTrue($saved);
        self::assertSame(BookingStatus::REJECTED, $b->status());
    }

    public function test_is_idempotent_on_already_rejected(): void
    {
        $b = $this->pending();
        $b->assignId(7);
        $b->reject();
        $useCase = new RejectBooking(
            find: fn () => $b,
            persist: fn () => null,
        );
        $useCase->execute(7);
        self::assertSame(BookingStatus::REJECTED, $b->status());
    }

    public function test_throws_when_missing(): void
    {
        $useCase = new RejectBooking(find: fn () => null, persist: fn () => null);
        $this->expectException(BookingNotFound::class);
        $useCase->execute(999);
    }
}
```

- [ ] **Step 2 : Rouge**

```bash
composer test -- --filter RejectBookingTest
```

- [ ] **Step 3 : Implémenter `RejectBooking`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Booking;

use Closure;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Domain\Booking;

final class RejectBooking
{
    /**
     * @param Closure(int): ?Booking $find
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $find,
        private readonly Closure $persist,
    ) {
    }

    public function execute(int $bookingId): Booking
    {
        $booking = ($this->find)($bookingId);
        if ($booking === null) {
            throw new BookingNotFound("Booking {$bookingId} not found.");
        }
        $booking->reject();
        ($this->persist)($booking);
        return $booking;
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Booking/RejectBooking.php tests/Unit/Booking/RejectBookingTest.php
git commit -m "feat(booking): add RejectBooking use case"
```

---

## Task 4 : Notifications — `EventKey` enum

**Files:**
- Create: `src/Notifications/Events/EventKey.php`
- Create: `tests/Unit/Notifications/EventKeyTest.php`

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\Events\EventKey;

final class EventKeyTest extends TestCase
{
    public function test_lists_six_event_keys(): void
    {
        $keys = array_map(fn (EventKey $e) => $e->value, EventKey::cases());
        self::assertSame([
            'booking.pending.client',
            'booking.pending.admin',
            'booking.confirmed.client',
            'booking.rejected.client',
            'booking.cancelled.client',
            'booking.reminder.client',
        ], $keys);
    }

    public function test_recipient_returns_admin_or_client(): void
    {
        self::assertSame('client', EventKey::PENDING_CLIENT->recipient());
        self::assertSame('admin',  EventKey::PENDING_ADMIN->recipient());
        self::assertSame('client', EventKey::CONFIRMED_CLIENT->recipient());
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications\Events;

enum EventKey: string
{
    case PENDING_CLIENT   = 'booking.pending.client';
    case PENDING_ADMIN    = 'booking.pending.admin';
    case CONFIRMED_CLIENT = 'booking.confirmed.client';
    case REJECTED_CLIENT  = 'booking.rejected.client';
    case CANCELLED_CLIENT = 'booking.cancelled.client';
    case REMINDER_CLIENT  = 'booking.reminder.client';

    public function recipient(): string
    {
        return $this === self::PENDING_ADMIN ? 'admin' : 'client';
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/Events/EventKey.php tests/Unit/Notifications/EventKeyTest.php
git commit -m "feat(notifications): add EventKey enum (6 events)"
```

---

## Task 5 : Notifications — `TagRegistry`

**Files:**
- Create: `src/Notifications/TagRegistry.php`
- Create: `tests/Unit/Notifications/TagRegistryTest.php`

Source of truth pour la liste des tags `{{...}}`, leurs catégories, leurs descriptions et le sous-ensemble `RAW_TAGS` (URLs déjà escapées, blocs HTML laissés bruts).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\TagRegistry;

final class TagRegistryTest extends TestCase
{
    public function test_known_tag_returns_metadata(): void
    {
        $r = new TagRegistry();
        $tag = $r->find('customer_name');
        self::assertNotNull($tag);
        self::assertSame('customer', $tag['category']);
        self::assertFalse($tag['raw']);
    }

    public function test_url_tag_is_raw(): void
    {
        $r = new TagRegistry();
        self::assertTrue($r->find('cancel_url')['raw']);
        self::assertTrue($r->find('confirm_url')['raw']);
    }

    public function test_unknown_tag_returns_null(): void
    {
        self::assertNull((new TagRegistry())->find('nope'));
    }

    public function test_grouped_returns_categories_with_tags(): void
    {
        $grouped = (new TagRegistry())->grouped();
        self::assertArrayHasKey('customer',   $grouped);
        self::assertArrayHasKey('appointment',$grouped);
        self::assertArrayHasKey('actions',    $grouped);
        self::assertArrayHasKey('site',       $grouped);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

/**
 * @phpstan-type Tag array{name:string, category:string, description:string, raw:bool}
 */
final class TagRegistry
{
    private const RAW_TAGS = ['cancel_url', 'confirm_url', 'reject_url', 'ics_url', 'company_logo'];

    /** @var array<string, Tag> */
    private array $tags;

    public function __construct()
    {
        $this->tags = $this->buildTags();
    }

    /**
     * @return Tag|null
     */
    public function find(string $name): ?array
    {
        return $this->tags[$name] ?? null;
    }

    /**
     * @return array<string, list<Tag>>
     */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->tags as $tag) {
            $out[$tag['category']][] = $tag;
        }
        return $out;
    }

    /**
     * @return array<string, Tag>
     */
    private function buildTags(): array
    {
        $defs = [
            ['customer', 'customer_name',    'Nom du client'],
            ['customer', 'customer_email',   'E-mail du client'],
            ['customer', 'customer_phone',   'Téléphone du client'],
            ['customer', 'customer_address', 'Adresse du client'],
            ['appointment', 'service_name',     'Nom du service'],
            ['appointment', 'service_duration', 'Durée du service'],
            ['appointment', 'appointment_date', 'Date du RDV (long, locale)'],
            ['appointment', 'appointment_time', 'Heure de début (HH:mm)'],
            ['appointment', 'appointment_end',  'Heure de fin (HH:mm)'],
            ['appointment', 'timezone',         'Fuseau horaire'],
            ['appointment', 'notes',            'Notes du client'],
            ['actions', 'cancel_url',  'URL d\'annulation client'],
            ['actions', 'confirm_url', 'URL de confirmation admin'],
            ['actions', 'reject_url',  'URL de refus admin'],
            ['actions', 'ics_url',     'URL téléchargement .ics'],
            ['site', 'site_name',     'Nom du site'],
            ['site', 'site_url',      'URL du site'],
            ['site', 'admin_email',   'E-mail admin'],
            ['site', 'company_logo',  'Balise <img> du logo (option plugin)'],
            ['site', 'company_phone', 'Téléphone société (option plugin)'],
        ];
        $out = [];
        foreach ($defs as [$cat, $name, $desc]) {
            $out[$name] = [
                'name'        => $name,
                'category'    => $cat,
                'description' => $desc,
                'raw'         => in_array($name, self::RAW_TAGS, true),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/TagRegistry.php tests/Unit/Notifications/TagRegistryTest.php
git commit -m "feat(notifications): add TagRegistry with categories and raw flag"
```

---

## Task 6 : Notifications — `TemplateRenderer`

**Files:**
- Create: `src/Notifications/TemplateRenderer.php`
- Create: `tests/Unit/Notifications/TemplateRendererTest.php`

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Notifications\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $r;

    protected function setUp(): void
    {
        $this->r = new TemplateRenderer(new TagRegistry());
    }

    public function test_replaces_known_tag(): void
    {
        $out = $this->r->render('Bonjour {{customer_name}}', ['customer_name' => 'Jean']);
        self::assertSame('Bonjour Jean', $out);
    }

    public function test_html_escapes_by_default(): void
    {
        $out = $this->r->render('Nom : {{customer_name}}', ['customer_name' => '<b>X</b>']);
        self::assertSame('Nom : &lt;b&gt;X&lt;/b&gt;', $out);
    }

    public function test_raw_tag_is_not_escaped(): void
    {
        $url = 'https://x.tld/cancel?sig=abc&exp=1';
        $out = $this->r->render('Lien {{cancel_url}}', ['cancel_url' => $url]);
        self::assertSame("Lien {$url}", $out);
    }

    public function test_unknown_tag_left_intact(): void
    {
        $out = $this->r->render('Hello {{nope}}', []);
        self::assertSame('Hello {{nope}}', $out);
    }

    public function test_multiple_tags_in_subject(): void
    {
        $out = $this->r->render('{{service_name}} - {{customer_name}}', [
            'service_name' => 'PV', 'customer_name' => 'Jean',
        ]);
        self::assertSame('PV - Jean', $out);
    }

    public function test_missing_value_for_known_tag_renders_empty(): void
    {
        $out = $this->r->render('A{{customer_name}}B', []);
        self::assertSame('AB', $out);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

final class TemplateRenderer
{
    public function __construct(private readonly TagRegistry $tags)
    {
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function render(string $template, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z_][a-z0-9_]*)\}\}/i',
            function (array $m) use ($data): string {
                $name = $m[1];
                $tag  = $this->tags->find($name);
                if ($tag === null) {
                    return $m[0]; // unknown → leave as-is
                }
                $value = (string) ($data[$name] ?? '');
                if ($tag['raw']) {
                    return $value;
                }
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $template,
        );
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/TemplateRenderer.php tests/Unit/Notifications/TemplateRendererTest.php
git commit -m "feat(notifications): add TemplateRenderer with HTML-safe {{tag}} parser"
```

---

## Task 7 : Notifications — `TextBodyGenerator`

**Files:**
- Create: `src/Notifications/TextBodyGenerator.php`
- Create: `tests/Unit/Notifications/TextBodyGeneratorTest.php`

Fallback texte pour les MUAs sans HTML. Si le template a une version texte custom, on l'utilise telle quelle ; sinon on dérive du HTML.

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\TextBodyGenerator;

final class TextBodyGeneratorTest extends TestCase
{
    public function test_strips_tags_and_normalises_whitespace(): void
    {
        $html = "<p>Bonjour <strong>Jean</strong>,</p><p>Votre RDV.</p>";
        $out = (new TextBodyGenerator())->fromHtml($html);
        self::assertSame("Bonjour Jean,\n\nVotre RDV.", $out);
    }

    public function test_converts_br_to_newline(): void
    {
        $out = (new TextBodyGenerator())->fromHtml('Ligne 1<br>Ligne 2');
        self::assertSame("Ligne 1\nLigne 2", $out);
    }

    public function test_keeps_link_href_inline(): void
    {
        $html = 'Clic <a href="https://x.tld/c?sig=1">ici</a> pour annuler.';
        $out = (new TextBodyGenerator())->fromHtml($html);
        self::assertSame('Clic ici (https://x.tld/c?sig=1) pour annuler.', $out);
    }

    public function test_decodes_entities(): void
    {
        $out = (new TextBodyGenerator())->fromHtml('Coût : 30&nbsp;€ &amp; plus');
        self::assertSame('Coût : 30 € & plus', $out);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

final class TextBodyGenerator
{
    public function fromHtml(string $html): string
    {
        // Inline <a href="X">Y</a> → "Y (X)"
        $html = (string) preg_replace_callback(
            '#<a\s+[^>]*href=("|\')(.*?)\1[^>]*>(.*?)</a>#is',
            static fn (array $m): string => $m[3] . ' (' . $m[2] . ')',
            $html,
        );

        // Block-level breaks become double newlines, <br> single
        $html = (string) preg_replace('#</(p|div|h\d|li|tr)>#i', "$0\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode entities (UTF-8, including nbsp)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);

        // Collapse 3+ newlines, trim each line, trim global
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $lines = array_map('trim', explode("\n", $text));
        return trim(implode("\n", $lines));
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/TextBodyGenerator.php tests/Unit/Notifications/TextBodyGeneratorTest.php
git commit -m "feat(notifications): add TextBodyGenerator for plain-text fallback"
```

---

## Task 8 : Notifications — `IcsBuilder`

**Files:**
- Create: `src/Notifications/IcsBuilder.php`
- Create: `tests/Unit/Notifications/IcsBuilderTest.php`

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\IcsBuilder;

final class IcsBuilderTest extends TestCase
{
    public function test_builds_minimal_vevent(): void
    {
        $ics = (new IcsBuilder())->build(
            uid: 'abc-123@slashbooking',
            summary: 'RDV Photovoltaïque',
            description: 'Jean, 1 rue X',
            startUtc: new DateTimeImmutable('2026-06-01T12:00:00Z', new DateTimeZone('UTC')),
            endUtc:   new DateTimeImmutable('2026-06-01T13:30:00Z', new DateTimeZone('UTC')),
        );

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("VERSION:2.0\r\n", $ics);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        self::assertStringContainsString("UID:abc-123@slashbooking\r\n", $ics);
        self::assertStringContainsString("DTSTART:20260601T120000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20260601T133000Z\r\n", $ics);
        self::assertStringContainsString("SUMMARY:RDV Photovoltaïque\r\n", $ics);
        self::assertStringContainsString("END:VEVENT\r\n", $ics);
        self::assertStringContainsString("END:VCALENDAR\r\n", $ics);
    }

    public function test_escapes_commas_semicolons_backslashes_and_newlines(): void
    {
        $ics = (new IcsBuilder())->build(
            uid: 'x@y',
            summary: 'A, B; C\\ D',
            description: "Ligne 1\nLigne 2",
            startUtc: new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC')),
            endUtc:   new DateTimeImmutable('2026-06-01T11:00:00Z', new DateTimeZone('UTC')),
        );
        self::assertStringContainsString('SUMMARY:A\\, B\\; C\\\\ D', $ics);
        self::assertStringContainsString('DESCRIPTION:Ligne 1\\nLigne 2', $ics);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use DateTimeImmutable;

final class IcsBuilder
{
    private const CRLF = "\r\n";

    public function build(
        string $uid,
        string $summary,
        string $description,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
    ): string {
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SlashBooking//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $startUtc->format('Ymd\THis\Z'),
            'DTEND:'   . $endUtc->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape($description),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode(self::CRLF, $lines) . self::CRLF;
    }

    private function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ','  => '\\,',
            ';'  => '\\;',
            "\n" => '\\n',
            "\r" => '',
        ]);
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/IcsBuilder.php tests/Unit/Notifications/IcsBuilderTest.php
git commit -m "feat(notifications): add RFC 5545 IcsBuilder"
```

---

## Task 9 : Notifications — `DefaultTemplates`

**Files:**
- Create: `src/Notifications/DefaultTemplates.php`
- Create: `tests/Unit/Notifications/DefaultTemplatesTest.php`

Templates par défaut bundlés, retournés depuis du PHP pour être livrés avec le plugin (pas de fichier HTML séparé en V1 — on évite l'I/O à l'activation).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\DefaultTemplates;
use Slash\Booking\Notifications\Events\EventKey;

final class DefaultTemplatesTest extends TestCase
{
    public function test_returns_template_for_each_event_key(): void
    {
        $defaults = DefaultTemplates::all();
        foreach (EventKey::cases() as $event) {
            self::assertArrayHasKey($event->value, $defaults);
            $tpl = $defaults[$event->value];
            self::assertNotEmpty($tpl['subject']);
            self::assertNotEmpty($tpl['html_body']);
        }
    }

    public function test_confirmed_template_includes_appointment_tags(): void
    {
        $tpl = DefaultTemplates::all()[EventKey::CONFIRMED_CLIENT->value];
        self::assertStringContainsString('{{appointment_date}}', $tpl['html_body']);
        self::assertStringContainsString('{{appointment_time}}', $tpl['html_body']);
        self::assertStringContainsString('{{cancel_url}}', $tpl['html_body']);
    }

    public function test_admin_pending_template_includes_decision_links(): void
    {
        $tpl = DefaultTemplates::all()[EventKey::PENDING_ADMIN->value];
        self::assertStringContainsString('{{confirm_url}}', $tpl['html_body']);
        self::assertStringContainsString('{{reject_url}}', $tpl['html_body']);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Slash\Booking\Notifications\Events\EventKey;

final class DefaultTemplates
{
    /**
     * @return array<string, array{subject:string, html_body:string}>
     */
    public static function all(): array
    {
        return [
            EventKey::PENDING_CLIENT->value => [
                'subject'   => __('Votre demande de RDV — en attente de validation', 'slashbooking'),
                'html_body' => self::pendingClient(),
            ],
            EventKey::PENDING_ADMIN->value => [
                'subject'   => __('Nouvelle demande de RDV : {{service_name}} — {{customer_name}}', 'slashbooking'),
                'html_body' => self::pendingAdmin(),
            ],
            EventKey::CONFIRMED_CLIENT->value => [
                'subject'   => __('RDV confirmé — {{appointment_date}} à {{appointment_time}}', 'slashbooking'),
                'html_body' => self::confirmedClient(),
            ],
            EventKey::REJECTED_CLIENT->value => [
                'subject'   => __('Votre demande de RDV n\'a pas pu être confirmée', 'slashbooking'),
                'html_body' => self::rejectedClient(),
            ],
            EventKey::CANCELLED_CLIENT->value => [
                'subject'   => __('Annulation de votre RDV confirmée', 'slashbooking'),
                'html_body' => self::cancelledClient(),
            ],
            EventKey::REMINDER_CLIENT->value => [
                'subject'   => __('Rappel : RDV demain à {{appointment_time}}', 'slashbooking'),
                'html_body' => self::reminderClient(),
            ],
        ];
    }

    private static function pendingClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Nous avons bien reçu votre demande de rendez-vous pour <strong>{{service_name}}</strong> le <strong>{{appointment_date}}</strong> à <strong>{{appointment_time}}</strong>.</p>
<p>Notre équipe vous contactera très vite pour la confirmer.</p>
<p>Vous pouvez annuler à tout moment : <a href="{{cancel_url}}">annuler ce RDV</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }

    private static function pendingAdmin(): string
    {
        return <<<HTML
<p>Nouvelle demande de RDV à valider :</p>
<ul>
  <li><strong>Service :</strong> {{service_name}} ({{service_duration}})</li>
  <li><strong>Quand :</strong> {{appointment_date}} de {{appointment_time}} à {{appointment_end}}</li>
  <li><strong>Client :</strong> {{customer_name}} — {{customer_email}} — {{customer_phone}}</li>
  <li><strong>Adresse :</strong> {{customer_address}}</li>
  <li><strong>Notes :</strong> {{notes}}</li>
</ul>
<p>
  <a href="{{confirm_url}}" style="background:#16a34a;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Confirmer</a>
  &nbsp;
  <a href="{{reject_url}}" style="background:#dc2626;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Refuser</a>
</p>
<p style="font-size:12px;color:#666">Les liens expirent dans 72 h.</p>
HTML;
    }

    private static function confirmedClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Votre RDV <strong>{{service_name}}</strong> est confirmé pour le <strong>{{appointment_date}}</strong> de {{appointment_time}} à {{appointment_end}} ({{timezone}}).</p>
<p>Adresse renseignée : {{customer_address}}</p>
<p>Vous pouvez l'ajouter à votre agenda via la pièce jointe .ics, ou <a href="{{cancel_url}}">annuler ce RDV</a>.</p>
<p>À très vite !<br>{{site_name}}</p>
HTML;
    }

    private static function rejectedClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Désolé, votre demande de RDV pour le {{appointment_date}} à {{appointment_time}} n'a pas pu être confirmée.</p>
<p>N'hésitez pas à <a href="{{site_url}}">choisir un autre créneau</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }

    private static function cancelledClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Nous avons bien pris en compte l'annulation de votre RDV {{service_name}} du {{appointment_date}} à {{appointment_time}}.</p>
<p>À très vite ! <a href="{{site_url}}">Reprendre un RDV</a>.</p>
HTML;
    }

    private static function reminderClient(): string
    {
        return <<<HTML
<p>Bonjour {{customer_name}},</p>
<p>Petit rappel : votre RDV <strong>{{service_name}}</strong> est prévu <strong>demain</strong> à {{appointment_time}} ({{timezone}}).</p>
<p>Adresse : {{customer_address}}</p>
<p>Besoin d'annuler ? <a href="{{cancel_url}}">Cliquer ici</a>.</p>
<p>— {{site_name}}</p>
HTML;
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test
composer stan
git add src/Notifications/DefaultTemplates.php tests/Unit/Notifications/DefaultTemplatesTest.php
git commit -m "feat(notifications): bundle 6 default HTML email templates"
```

---

## Task 10 : Persistence — `MailTemplateRepository`

**Files:**
- Create: `src/Persistence/MailTemplateRepository.php`
- Create: `tests/Integration/MailTemplateRepositoryTest.php`

Repository CRUD sur la table `wp_sb_mail_templates`. Méthode clé `getOrDefault($eventKey)` → retourne template custom si présent et `enabled`, sinon défaut bundlé.

- [ ] **Step 1 : Test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use Slash\Booking\Activator;
use Slash\Booking\Persistence\MailTemplateRepository;
use Slash\Booking\Notifications\Events\EventKey;
use WP_UnitTestCase;

final class MailTemplateRepositoryTest extends WP_UnitTestCase
{
    private MailTemplateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->repo = new MailTemplateRepository($wpdb);
    }

    public function test_default_when_no_custom_template(): void
    {
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertNotEmpty($tpl['subject']);
        self::assertNotEmpty($tpl['html_body']);
    }

    public function test_save_then_read_custom_template(): void
    {
        $this->repo->save(
            event: EventKey::CONFIRMED_CLIENT,
            subject: 'Sujet custom',
            htmlBody: '<p>Custom</p>',
            textBody: null,
            enabled: true,
            updatedBy: 1,
        );
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertSame('Sujet custom', $tpl['subject']);
        self::assertSame('<p>Custom</p>', $tpl['html_body']);
    }

    public function test_disabled_custom_falls_back_to_default(): void
    {
        $this->repo->save(
            event: EventKey::CONFIRMED_CLIENT,
            subject: 'Sujet custom', htmlBody: '<p>Custom</p>',
            textBody: null, enabled: false, updatedBy: 1,
        );
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertStringNotContainsString('Sujet custom', $tpl['subject']);
    }

    public function test_delete_falls_back_to_default(): void
    {
        $this->repo->save(EventKey::CONFIRMED_CLIENT, 'X', '<p>X</p>', null, true, 1);
        $this->repo->delete(EventKey::CONFIRMED_CLIENT);
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertStringNotContainsString('X', $tpl['html_body']);
    }
}
```

- [ ] **Step 2 : Rouge**

```bash
composer test:integration -- --filter MailTemplateRepositoryTest
```
(skip si WP test suite non installée — c'est normal)

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Persistence;

use Slash\Booking\Notifications\DefaultTemplates;
use Slash\Booking\Notifications\Events\EventKey;
use wpdb;

/**
 * @phpstan-type Template array{
 *   subject:string,
 *   html_body:string,
 *   text_body:?string,
 *   enabled:bool,
 *   is_custom:bool,
 *   updated_at:?string,
 *   updated_by:?int,
 * }
 */
final class MailTemplateRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'sb_mail_templates';
    }

    /**
     * @return Template
     */
    public function getOrDefault(EventKey $event): array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE event_key = %s",
                $event->value,
            ),
            ARRAY_A
        );

        if (is_array($row) && (int) $row['enabled'] === 1) {
            return [
                'subject'    => (string) $row['subject'],
                'html_body'  => (string) $row['html_body'],
                'text_body'  => $row['text_body'] !== null ? (string) $row['text_body'] : null,
                'enabled'    => true,
                'is_custom'  => true,
                'updated_at' => (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            ];
        }

        $defaults = DefaultTemplates::all();
        $default = $defaults[$event->value];
        return [
            'subject'    => $default['subject'],
            'html_body'  => $default['html_body'],
            'text_body'  => null,
            'enabled'    => true,
            'is_custom'  => false,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    public function save(
        EventKey $event,
        string $subject,
        string $htmlBody,
        ?string $textBody,
        bool $enabled,
        int $updatedBy,
    ): void {
        $now = current_time('mysql', true);
        $data = [
            'event_key'  => $event->value,
            'subject'    => $subject,
            'html_body'  => $htmlBody,
            'text_body'  => $textBody,
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => $now,
            'updated_by' => $updatedBy,
        ];
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT id FROM {$this->table} WHERE event_key = %s", $event->value)
        );
        if ($existing !== null) {
            $this->wpdb->update($this->table, $data, ['event_key' => $event->value]);
            return;
        }
        $this->wpdb->insert($this->table, $data);
    }

    public function delete(EventKey $event): void
    {
        $this->wpdb->delete($this->table, ['event_key' => $event->value]);
    }
}
```

- [ ] **Step 4 : Vert (intégration) + commit**

```bash
composer test:integration -- --filter MailTemplateRepositoryTest
composer stan
git add src/Persistence/MailTemplateRepository.php tests/Integration/MailTemplateRepositoryTest.php
git commit -m "feat(persistence): MailTemplateRepository with default fallback"
```

---

## Task 11 : Notifications — `BookingContext` DTO

**Files:**
- Create: `src/Notifications/Events/BookingContext.php`
- Create: `tests/Unit/Notifications/BookingContextTest.php`

DTO qui transforme un `Booking` + `Service` en `array<string, scalar>` consommable par `TemplateRenderer`. Centralise tout le formatage (dates locales, URLs signées).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Notifications\Events\BookingContext;

final class BookingContextTest extends TestCase
{
    public function test_serialises_booking_and_service_to_tag_dataset(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: '1 rue X',
            customerMeta: [], notes: 'RAS',
        );
        $b->assignId(42);
        $svc = new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );

        $ctx = BookingContext::fromBooking($b, $svc, [
            'site_name'    => 'Trinity',
            'site_url'     => 'https://t.tld',
            'admin_email'  => 'admin@t.tld',
            'company_phone'=> '0102',
            'company_logo' => '',
            'cancel_url'   => 'https://t.tld/cancel?sig=1',
            'confirm_url'  => 'https://t.tld/decide?sig=2',
            'reject_url'   => 'https://t.tld/decide?sig=3',
            'ics_url'      => '',
        ]);

        $data = $ctx->toArray();
        self::assertSame('Jean',         $data['customer_name']);
        self::assertSame('Photovoltaïque', $data['service_name']);
        self::assertSame('1h30',          $data['service_duration']);
        self::assertSame('Europe/Paris',  $data['timezone']);
        self::assertSame('10:00',         $data['appointment_time']);
        self::assertSame('11:30',         $data['appointment_end']);
        self::assertSame('Trinity',       $data['site_name']);
        self::assertSame('https://t.tld/cancel?sig=1', $data['cancel_url']);
    }

    public function test_service_duration_uses_minutes_when_under_one_hour(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T08:45:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 2, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'A', customerEmail: 'a@a.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $svc = new Service(
            id: 2, slug: 'irve', name: 'IRVE', durationMin: 45,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );
        $ctx = BookingContext::fromBooking($b, $svc, []);
        self::assertSame('45 min', $ctx->toArray()['service_duration']);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications\Events;

use DateTimeZone;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;

/**
 * @phpstan-type Extra array{
 *   site_name?:string, site_url?:string, admin_email?:string,
 *   company_phone?:string, company_logo?:string,
 *   cancel_url?:string, confirm_url?:string, reject_url?:string, ics_url?:string,
 * }
 */
final class BookingContext
{
    /**
     * @param array<string, scalar|null> $data
     */
    private function __construct(public readonly array $data)
    {
    }

    /**
     * @param Extra $extra
     */
    public static function fromBooking(Booking $b, Service $svc, array $extra): self
    {
        $tz    = new DateTimeZone($b->timezone());
        $start = $b->slot()->start->setTimezone($tz);
        $end   = $b->slot()->end->setTimezone($tz);

        $data = [
            'customer_name'    => $b->customerName(),
            'customer_email'   => $b->customerEmail(),
            'customer_phone'   => $b->customerPhone(),
            'customer_address' => $b->customerAddress(),
            'service_name'     => $svc->name,
            'service_duration' => self::formatDuration($svc->durationMin),
            'appointment_date' => self::formatDate($start),
            'appointment_time' => $start->format('H:i'),
            'appointment_end'  => $end->format('H:i'),
            'timezone'         => $b->timezone(),
            'notes'            => $b->notes(),
            'cancel_url'       => (string) ($extra['cancel_url']  ?? ''),
            'confirm_url'      => (string) ($extra['confirm_url'] ?? ''),
            'reject_url'       => (string) ($extra['reject_url']  ?? ''),
            'ics_url'          => (string) ($extra['ics_url']     ?? ''),
            'site_name'        => (string) ($extra['site_name']   ?? ''),
            'site_url'         => (string) ($extra['site_url']    ?? ''),
            'admin_email'      => (string) ($extra['admin_email'] ?? ''),
            'company_logo'     => (string) ($extra['company_logo']  ?? ''),
            'company_phone'    => (string) ($extra['company_phone'] ?? ''),
        ];

        return new self($data);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . 'h' : sprintf('%dh%02d', $h, $m);
    }

    private static function formatDate(\DateTimeInterface $dt): string
    {
        // Format ISO simple côté domain (locale-free). Le rendu humain locale-aware se fait
        // dans MailDispatcher via wp_date() avant l'envoi.
        return $dt->format('Y-m-d');
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Notifications/Events/BookingContext.php tests/Unit/Notifications/BookingContextTest.php
git commit -m "feat(notifications): BookingContext DTO maps booking+service to tag dataset"
```

---

## Task 12 : HTTP — `UrlBuilder` (URLs signées HMAC)

**Files:**
- Create: `src/Http/UrlBuilder.php`
- Create: `tests/Unit/Http/UrlBuilderTest.php`

Centralise la construction des URLs `cancel`, `confirm`, `reject`, `ics` avec signature HMAC.

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Http\UrlBuilder;

final class UrlBuilderTest extends TestCase
{
    private UrlBuilder $b;

    protected function setUp(): void
    {
        $signer = new DecisionTokenSigner('a-very-long-test-secret-32bytes-ok');
        $this->b = new UrlBuilder($signer, 'https://t.tld/wp-json/slashbooking/v1');
    }

    public function test_cancel_url_has_uid_exp_sig(): void
    {
        $url = $this->b->cancelUrl('uid-123', 1900000000);
        self::assertStringContainsString('/cancel?', $url);
        self::assertStringContainsString('uid=uid-123', $url);
        self::assertStringContainsString('exp=1900000000', $url);
        self::assertStringContainsString('sig=', $url);
    }

    public function test_decision_urls_carry_action(): void
    {
        $confirm = $this->b->decisionUrl(42, 'confirm', 1900000000);
        $reject  = $this->b->decisionUrl(42, 'reject',  1900000000);
        self::assertStringContainsString('action=confirm', $confirm);
        self::assertStringContainsString('action=reject',  $reject);
        self::assertStringContainsString('booking=42', $confirm);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Booking\DecisionTokenSigner;

final class UrlBuilder
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly string $restBaseUrl,
    ) {
    }

    public function cancelUrl(string $publicUid, int $expiresAtUnix): string
    {
        $payload = 'cancel|' . $publicUid;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return $this->restBaseUrl . '/cancel?' . http_build_query([
            'uid' => $publicUid,
            'exp' => $expiresAtUnix,
            'sig' => $sig,
        ]);
    }

    public function decisionUrl(int $bookingId, string $action, int $expiresAtUnix): string
    {
        $payload = 'decide|' . $bookingId . '|' . $action;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return $this->restBaseUrl . '/decide?' . http_build_query([
            'booking' => $bookingId,
            'action'  => $action,
            'exp'     => $expiresAtUnix,
            'sig'     => $sig,
        ]);
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test && composer stan
git add src/Http/UrlBuilder.php tests/Unit/Http/UrlBuilderTest.php
git commit -m "feat(http): UrlBuilder centralises HMAC-signed action URLs"
```

---

## Task 13 : Notifications — `MailDispatcher`

**Files:**
- Create: `src/Notifications/MailDispatcher.php`
- Create: `tests/Integration/MailDispatcherTest.php`

Orchestrateur d'envoi : prend un `EventKey` + `BookingContext` + (optionnel) booking pour `.ics`, charge le template, render, génère version texte, envoie via `wp_mail`. Catch toute exception, loggue, continue.

- [ ] **Step 1 : Test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Notifications\Events\BookingContext;
use Slash\Booking\Notifications\Events\EventKey;
use Slash\Booking\Notifications\MailDispatcher;
use Slash\Booking\Notifications\IcsBuilder;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Notifications\TemplateRenderer;
use Slash\Booking\Notifications\TextBodyGenerator;
use Slash\Booking\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailDispatcherTest extends WP_UnitTestCase
{
    private MailDispatcher $dispatcher;

    /** @var list<array{to:string|array<string>, subject:string, message:string, headers:array<string>|string, attachments:array<string>|string}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->dispatcher = new MailDispatcher(
            templates: new MailTemplateRepository($wpdb),
            renderer:  new TemplateRenderer(new TagRegistry()),
            text:      new TextBodyGenerator(),
            ics:       new IcsBuilder(),
        );

        // Intercept wp_mail
        $self = $this;
        add_filter('pre_wp_mail', static function ($null, array $atts) use ($self): bool {
            $self->sent[] = $atts;
            return true;
        }, 10, 2);
    }

    public function test_sends_confirmed_email_with_ics_attachment(): void
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'jean@test.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $b->assignId(7);
        $svc = new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque', durationMin: 90,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );

        $ctx = BookingContext::fromBooking($b, $svc, ['site_name' => 'Trinity']);

        $this->dispatcher->send(
            event: EventKey::CONFIRMED_CLIENT,
            recipient: 'jean@test.fr',
            context: $ctx,
            withIcsFor: $b,
        );

        self::assertCount(1, $this->sent);
        self::assertSame('jean@test.fr', $this->sent[0]['to']);
        self::assertStringContainsString('Photovoltaïque', $this->sent[0]['subject']);
        self::assertNotEmpty($this->sent[0]['attachments']);
    }

    public function test_send_does_not_throw_when_wp_mail_fails(): void
    {
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', static fn () => false); // simulate failure

        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'A', customerEmail: 'a@a.fr',
            customerPhone: '0', customerAddress: '',
            customerMeta: [], notes: '',
        );
        $svc = new Service(
            id: 1, slug: 'pv', name: 'PV', durationMin: 90,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );

        $ctx = BookingContext::fromBooking($b, $svc, []);
        // doit retourner false et ne PAS lever
        $ok = $this->dispatcher->send(EventKey::PENDING_CLIENT, 'a@a.fr', $ctx);
        self::assertFalse($ok);
    }
}
```

- [ ] **Step 2 : Rouge** (classe manquante)

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Throwable;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Notifications\Events\BookingContext;
use Slash\Booking\Notifications\Events\EventKey;
use Slash\Booking\Persistence\MailTemplateRepository;

final class MailDispatcher
{
    public function __construct(
        private readonly MailTemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly TextBodyGenerator $text,
        private readonly IcsBuilder $ics,
    ) {
    }

    public function send(
        EventKey $event,
        string $recipient,
        BookingContext $context,
        ?Booking $withIcsFor = null,
    ): bool {
        try {
            $tpl  = $this->templates->getOrDefault($event);
            $data = $context->toArray();

            $subject = $this->renderer->render($tpl['subject'], $data);
            $html    = $this->renderer->render($tpl['html_body'], $data);
            $text    = $tpl['text_body'] !== null && $tpl['text_body'] !== ''
                ? $this->renderer->render($tpl['text_body'], $data)
                : $this->text->fromHtml($html);

            $boundary = 'sb-' . bin2hex(random_bytes(8));
            $headers = [
                'From: ' . $this->fromHeader(),
                'Reply-To: ' . $this->replyTo($recipient, $context),
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $text . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $html . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $attachments = [];
            if ($withIcsFor !== null) {
                $attachments[] = $this->writeIcsTempFile($withIcsFor, $subject);
            }

            $sent = wp_mail($recipient, $subject, $body, $headers, $attachments);

            foreach ($attachments as $path) {
                if (is_string($path) && file_exists($path)) {
                    @unlink($path);
                }
            }

            if (!$sent) {
                error_log(sprintf('[slashbooking] wp_mail failed for event=%s to=%s', $event->value, $recipient));
            }
            return (bool) $sent;
        } catch (Throwable $e) {
            error_log('[slashbooking] MailDispatcher exception: ' . $e->getMessage());
            return false;
        }
    }

    private function fromHeader(): string
    {
        $name  = (string) get_option('blogname', 'WordPress');
        $email = (string) get_option('admin_email', 'no-reply@example.com');
        return sprintf('%s <%s>', $name, $email);
    }

    private function replyTo(string $recipient, BookingContext $ctx): string
    {
        $admin = (string) ($ctx->toArray()['admin_email'] ?? get_option('admin_email', ''));
        return $admin !== '' ? $admin : $recipient;
    }

    private function writeIcsTempFile(Booking $b, string $summary): string
    {
        $ics = $this->ics->build(
            uid: $b->publicUid() . '@slashbooking',
            summary: $summary,
            description: '',
            startUtc: $b->slot()->start,
            endUtc:   $b->slot()->end,
        );
        $dir  = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $path = rtrim($dir, '/\\') . '/' . 'sb-' . $b->publicUid() . '.ics';
        file_put_contents($path, $ics);
        return $path;
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test:integration -- --filter MailDispatcherTest
composer stan
git add src/Notifications/MailDispatcher.php tests/Integration/MailDispatcherTest.php
git commit -m "feat(notifications): MailDispatcher renders template, attaches ICS, safe-fails"
```

---

## Task 14 : Notifications — `BookingNotifier` (hook listener)

**Files:**
- Create: `src/Notifications/BookingNotifier.php`
- Create: `tests/Integration/BookingNotifierTest.php`

Le `BookingNotifier` s'abonne à 5 hooks WP internes :
- `slashbooking/booking_created` → envoie `pending.client` + `pending.admin`
- `slashbooking/booking_confirmed` → envoie `confirmed.client` avec `.ics`
- `slashbooking/booking_rejected` → envoie `rejected.client`
- `slashbooking/booking_cancelled` → envoie `cancelled.client`
- `slashbooking/booking_reminder_due` → envoie `reminder.client`

Chaque hook reçoit l'`int $bookingId`. Le notifier charge booking + service depuis les repos, construit le contexte, dispatche.

- [ ] **Step 1 : Test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Notifications\BookingNotifier;
use Slash\Booking\Notifications\IcsBuilder;
use Slash\Booking\Notifications\MailDispatcher;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Notifications\TemplateRenderer;
use Slash\Booking\Notifications\TextBodyGenerator;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\MailTemplateRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Http\UrlBuilder;
use WP_UnitTestCase;

final class BookingNotifierTest extends WP_UnitTestCase
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $dispatcher = new MailDispatcher(
            new MailTemplateRepository($wpdb),
            new TemplateRenderer(new TagRegistry()),
            new TextBodyGenerator(),
            new IcsBuilder(),
        );
        $signer = new DecisionTokenSigner((string) get_option('sb_decision_secret'));
        $urls   = new UrlBuilder($signer, rest_url('slashbooking/v1'));

        $notifier = new BookingNotifier($services, $bookings, $dispatcher, $urls);
        $notifier->register();

        $self = $this;
        add_filter('pre_wp_mail', static function ($null, array $atts) use ($self): bool {
            $self->sent[] = $atts;
            return true;
        }, 10, 2);
    }

    public function test_booking_created_sends_two_emails(): void
    {
        $b = $this->newBooking('jean@test.fr');
        do_action('slashbooking/booking_created', $b->id());

        self::assertCount(2, $this->sent);
        $recipients = array_column($this->sent, 'to');
        self::assertContains('jean@test.fr', $recipients);
        // admin email comes from option(admin_email)
        self::assertContains(get_option('admin_email'), $recipients);
    }

    public function test_booking_confirmed_sends_one_email_with_ics(): void
    {
        $b = $this->newBooking('jean@test.fr');
        do_action('slashbooking/booking_confirmed', $b->id());

        self::assertCount(1, $this->sent);
        self::assertNotEmpty($this->sent[0]['attachments']);
    }

    private function newBooking(string $email): Booking
    {
        global $wpdb;
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: $email,
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        (new BookingRepository($wpdb))->save($b);
        return $b;
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Http\UrlBuilder;
use Slash\Booking\Notifications\Events\BookingContext;
use Slash\Booking\Notifications\Events\EventKey;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;

final class BookingNotifier
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly MailDispatcher    $dispatcher,
        private readonly UrlBuilder        $urls,
    ) {
    }

    public function register(): void
    {
        add_action('slashbooking/booking_created',    [$this, 'onCreated'],    10, 1);
        add_action('slashbooking/booking_confirmed',  [$this, 'onConfirmed'],  10, 1);
        add_action('slashbooking/booking_rejected',   [$this, 'onRejected'],   10, 1);
        add_action('slashbooking/booking_cancelled',  [$this, 'onCancelled'],  10, 1);
        add_action('slashbooking/booking_reminder_due', [$this, 'onReminderDue'], 10, 1);
    }

    public function onCreated(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $ctx = $this->context($b);
        $this->dispatcher->send(EventKey::PENDING_CLIENT, $b->customerEmail(), $ctx);
        $this->dispatcher->send(EventKey::PENDING_ADMIN, $this->adminEmail($ctx), $ctx);
    }

    public function onConfirmed(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::CONFIRMED_CLIENT, $b->customerEmail(), $this->context($b), $b);
    }

    public function onRejected(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::REJECTED_CLIENT, $b->customerEmail(), $this->context($b));
    }

    public function onCancelled(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::CANCELLED_CLIENT, $b->customerEmail(), $this->context($b));
    }

    public function onReminderDue(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::REMINDER_CLIENT, $b->customerEmail(), $this->context($b));
    }

    private function context(Booking $b): BookingContext
    {
        $svc = $this->services->findById($b->serviceId());
        if ($svc === null) {
            // Service supprimé : on essaie de rester functional avec un Service "fantôme".
            $svc = new \Slash\Booking\Domain\Service(
                id: $b->serviceId(), slug: 'unknown', name: 'Service',
                durationMin: max(1, (int) (($b->slot()->end->getTimestamp() - $b->slot()->start->getTimestamp()) / 60)),
                bufferBeforeMin: 0, bufferAfterMin: 0,
                minLeadTimeHours: 0, maxHorizonDays: 60,
                weeklyHours: [], active: true, color: '#000',
            );
        }

        $exp = time() + 72 * HOUR_IN_SECONDS;
        $extra = [
            'site_name'     => (string) get_option('blogname', ''),
            'site_url'      => (string) home_url('/'),
            'admin_email'   => (string) get_option('admin_email', ''),
            'company_phone' => (string) get_option('sb_company_phone', ''),
            'company_logo'  => (string) get_option('sb_company_logo', ''),
            'cancel_url'    => $this->urls->cancelUrl($b->publicUid(), $exp),
            'confirm_url'   => $b->id() !== null ? $this->urls->decisionUrl($b->id(), 'confirm', $exp) : '',
            'reject_url'    => $b->id() !== null ? $this->urls->decisionUrl($b->id(), 'reject',  $exp) : '',
            'ics_url'       => '',
        ];

        return BookingContext::fromBooking($b, $svc, $extra);
    }

    private function adminEmail(BookingContext $ctx): string
    {
        $email = (string) ($ctx->toArray()['admin_email'] ?? '');
        return $email !== '' ? $email : (string) get_option('admin_email', '');
    }
}
```

- [ ] **Step 4 : Vert + commit**

```bash
composer test:integration -- --filter BookingNotifierTest
composer stan
git add src/Notifications/BookingNotifier.php tests/Integration/BookingNotifierTest.php
git commit -m "feat(notifications): BookingNotifier wires WP hooks to MailDispatcher"
```

---

## Task 15 : Booking use cases — fire hooks

**Files:**
- Modify: `src/Booking/CreateBooking.php`
- Modify: `src/Booking/ConfirmBooking.php`
- Modify: `src/Booking/RejectBooking.php`
- Modify: `src/Booking/CancelBooking.php`
- Modify: `tests/Unit/Booking/*` pour les rendre tolérantes à `do_action` absent

Chaque use case émet son hook WP après persistance.

- [ ] **Step 1 : Adapter les tests unitaires**

Les tests Brain Monkey doivent stubber `do_action`. Vérifier que `tests/bootstrap.php` charge déjà Brain Monkey (sinon, l'ajouter à la suite Unit pour les use cases qui touchent à `do_action`).

Ajouter dans `tests/bootstrap.php` :

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        // no-op in unit tests
    }
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
```

- [ ] **Step 2 : Modifier `CreateBooking::execute` pour émettre le hook**

À la fin de la méthode, avant `return $booking;` :

```php
if (function_exists('do_action') && $booking->id() !== null) {
    do_action('slashbooking/booking_created', $booking->id());
}
```

- [ ] **Step 3 : Modifier `ConfirmBooking::execute`**

Avant `return $booking;` :

```php
if (function_exists('do_action') && $booking->id() !== null) {
    do_action('slashbooking/booking_confirmed', $booking->id());
}
```

- [ ] **Step 4 : Modifier `RejectBooking::execute`**

```php
if (function_exists('do_action') && $booking->id() !== null) {
    do_action('slashbooking/booking_rejected', $booking->id());
}
```

- [ ] **Step 5 : Modifier `CancelBooking::execute`**

```php
if (function_exists('do_action') && $booking->id() !== null) {
    do_action('slashbooking/booking_cancelled', $booking->id());
}
```

- [ ] **Step 6 : Lancer suite complète**

```bash
composer test
composer stan
composer cs
```
Tous verts.

- [ ] **Step 7 : Commit**

```bash
git add src/Booking/ tests/bootstrap.php
git commit -m "feat(booking): use cases emit WP action hooks after persistence"
```

---

## Task 16 : HTTP — `DecisionController` (boutons e-mail)

**Files:**
- Create: `src/Http/DecisionController.php`
- Create: `tests/Integration/DecisionControllerTest.php`

Endpoint `GET /slashbooking/v1/decide?booking={id}&action={confirm|reject}&exp={ts}&sig={hmac}` qui :
1. Vérifie HMAC + expiration.
2. Exécute `ConfirmBooking` ou `RejectBooking` (idempotent).
3. Affiche une page d'atterrissage HTML simple (pas du JSON — c'est lu dans un navigateur depuis un mail).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Http\UrlBuilder;
use Slash\Booking\Persistence\BookingRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class DecisionControllerTest extends WP_UnitTestCase
{
    private DecisionTokenSigner $signer;
    private UrlBuilder $urls;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
        $this->signer = new DecisionTokenSigner((string) get_option('sb_decision_secret'));
        $this->urls   = new UrlBuilder($this->signer, rest_url('slashbooking/v1'));
    }

    public function test_confirm_transitions_pending_to_confirmed(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/slashbooking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::CONFIRMED, $refreshed->status());
    }

    public function test_reject_transitions_pending_to_rejected(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|reject', $exp);
        $request = new WP_REST_Request('GET', '/slashbooking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'reject', 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::REJECTED, $refreshed->status());
    }

    public function test_invalid_signature_returns_403(): void
    {
        $b = $this->seedPending();
        $request = new WP_REST_Request('GET', '/slashbooking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => time() + 60, 'sig' => 'bogus']);
        $response = rest_do_request($request);
        self::assertSame(403, $response->get_status());
    }

    public function test_expired_token_returns_403(): void
    {
        $b = $this->seedPending();
        $exp = time() - 10;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/slashbooking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        self::assertSame(403, rest_do_request($request)->get_status());
    }

    public function test_idempotent_second_confirm_is_200(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/slashbooking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        rest_do_request($request);
        self::assertSame(200, rest_do_request($request)->get_status());
    }

    private function seedPending(): Booking
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'jean@test.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $repo->save($b);
        return $b;
    }
}
```

- [ ] **Step 2 : Rouge** (route inexistante → 404)

- [ ] **Step 3 : Implémenter `DecisionController`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Booking\ConfirmBooking;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Booking\RejectBooking;
use Slash\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DecisionController
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly ConfirmBooking $confirm,
        private readonly RejectBooking  $reject,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/decide',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'booking' => ['type' => 'integer', 'required' => true],
                    'action'  => ['type' => 'string',  'required' => true, 'enum' => ['confirm', 'reject']],
                    'exp'     => ['type' => 'integer', 'required' => true],
                    'sig'     => ['type' => 'string',  'required' => true],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request['booking'];
        $action = (string) $request['action'];
        $exp    = (int) $request['exp'];
        $sig    = (string) $request['sig'];

        if (!in_array($action, ['confirm', 'reject'], true)) {
            return new WP_Error('sb_invalid_action', 'Action inconnue.', ['status' => 400]);
        }

        $payload = 'decide|' . $id . '|' . $action;
        if (!$this->signer->verify($payload, $exp, $sig)) {
            return $this->htmlResponse(403, '<h1>Lien invalide ou expiré</h1><p>Demandez un nouveau lien.</p>');
        }

        try {
            if ($action === 'confirm') {
                $this->confirm->execute($id);
                $message = '<h1>RDV confirmé ✓</h1><p>Le client a été notifié.</p>';
            } else {
                $this->reject->execute($id);
                $message = '<h1>RDV refusé</h1><p>Le client a été notifié.</p>';
            }
        } catch (BookingNotFound $e) {
            return $this->htmlResponse(404, '<h1>Réservation introuvable</h1>');
        } catch (\DomainException $e) {
            return $this->htmlResponse(
                409,
                '<h1>Impossible</h1><p>' . esc_html($e->getMessage()) . '</p>',
            );
        }

        return $this->htmlResponse(200, $message);
    }

    private function htmlResponse(int $status, string $body): WP_REST_Response
    {
        $response = new WP_REST_Response(
            $this->wrapHtml($body),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
        return $response;
    }

    private function wrapHtml(string $inner): string
    {
        $title = esc_html__('Décision RDV', 'slashbooking');
        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{$title}</title>
<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#111}</style>
</head><body>{$inner}</body></html>
HTML;
    }
}
```

- [ ] **Step 4 : Brancher dans `RestRouter`**

Dans `src/Http/RestRouter.php`, à la fin de `registerRoutes()` :

```php
$confirmUC = new \Slash\Booking\Booking\ConfirmBooking(
    find: fn (int $id) => $bookings->findById($id),
    persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
);
$rejectUC = new \Slash\Booking\Booking\RejectBooking(
    find: fn (int $id) => $bookings->findById($id),
    persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
);
(new DecisionController($signer, $confirmUC, $rejectUC))->registerRoutes();
```

- [ ] **Step 5 : Vert + PHPStan + commit**

```bash
composer test:integration -- --filter DecisionControllerTest
composer stan
git add src/Http/DecisionController.php src/Http/RestRouter.php tests/Integration/DecisionControllerTest.php
git commit -m "feat(http): DecisionController for HMAC confirm/reject email buttons"
```

---

## Task 17 : Admin — `Capabilities` seed

**Files:**
- Create: `src/Admin/Capabilities.php`
- Modify: `src/Activator.php` (appel à `Capabilities::install()`)
- Modify: `src/Deactivator.php` (no-op pour V1 — caps restent sur les rôles tant que le plugin est installé)
- Create: `tests/Integration/CapabilitiesTest.php`

Caps : `slashbooking_manage` (admins), `slashbooking_view` (admins).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use Slash\Booking\Activator;
use WP_UnitTestCase;

final class CapabilitiesTest extends WP_UnitTestCase
{
    public function test_admin_role_has_booking_caps(): void
    {
        Activator::activate();
        $role = get_role('administrator');
        self::assertNotNull($role);
        self::assertTrue($role->has_cap('slashbooking_manage'));
        self::assertTrue($role->has_cap('slashbooking_view'));
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter `Capabilities`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class Capabilities
{
    public const MANAGE = 'slashbooking_manage';
    public const VIEW   = 'slashbooking_view';

    public static function install(): void
    {
        $role = get_role('administrator');
        if ($role === null) {
            return;
        }
        $role->add_cap(self::MANAGE);
        $role->add_cap(self::VIEW);
    }

    public static function uninstall(): void
    {
        foreach (['administrator', 'editor'] as $roleName) {
            $role = get_role($roleName);
            if ($role === null) {
                continue;
            }
            $role->remove_cap(self::MANAGE);
            $role->remove_cap(self::VIEW);
        }
    }
}
```

- [ ] **Step 4 : Hook dans Activator**

Dans `src/Activator.php::activate()`, après `seedServices` :

```php
Admin\Capabilities::install();
self::seedMailTemplates(); // anticipation Task 21
```

(Ajouter `self::seedMailTemplates()` comme méthode vide pour l'instant si Task 21 pas encore atteinte, ou la laisser à part — Task 21 ajoutera la définition.)

- [ ] **Step 5 : Vert + commit**

```bash
composer test:integration -- --filter CapabilitiesTest
composer stan
git add src/Admin/Capabilities.php src/Activator.php tests/Integration/CapabilitiesTest.php
git commit -m "feat(admin): seed slashbooking_manage/view caps on admin role"
```

---

## Task 18 : HTTP — `AdminBookingController` (REST admin)

**Files:**
- Create: `src/Http/AdminBookingController.php`
- Create: `tests/Integration/AdminBookingControllerTest.php`
- Modify: `src/Http/RestRouter.php`
- Modify: `src/Persistence/BookingRepository.php` (ajouter `paginate()`)

Endpoints :
- `GET /admin/bookings?status=&service=&from=&to=&page=&per_page=` → liste paginée
- `POST /admin/bookings/{id}/confirm` → idempotent
- `POST /admin/bookings/{id}/reject`
- `POST /admin/bookings/{id}/cancel`

Auth : `current_user_can('slashbooking_manage')` + nonce X-WP-Nonce.

- [ ] **Step 1 : Étendre `BookingRepository` avec `paginate()`**

Ajouter dans `src/Persistence/BookingRepository.php` :

```php
/**
 * @param array{status?:?string, service_id?:?int, from?:?\DateTimeImmutable, to?:?\DateTimeImmutable} $filters
 * @return array{items:list<Booking>, total:int, page:int, per_page:int}
 */
public function paginate(array $filters, int $page, int $perPage): array
{
    $page    = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $where   = [];
    $args    = [];

    if (!empty($filters['status'])) {
        $where[] = 'status = %s';
        $args[]  = $filters['status'];
    }
    if (!empty($filters['service_id'])) {
        $where[] = 'service_id = %d';
        $args[]  = (int) $filters['service_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 'starts_at_utc >= %s';
        $args[]  = $filters['from']->format('Y-m-d H:i:s');
    }
    if (!empty($filters['to'])) {
        $where[] = 'starts_at_utc < %s';
        $args[]  = $filters['to']->format('Y-m-d H:i:s');
    }

    $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $offset   = ($page - 1) * $perPage;

    $totalSql = "SELECT COUNT(*) FROM {$this->table}" . $whereSql;
    $total    = (int) $this->wpdb->get_var(
        $args === [] ? $totalSql : $this->wpdb->prepare($totalSql, ...$args)
    );

    $listSql = "SELECT * FROM {$this->table}" . $whereSql .
        " ORDER BY starts_at_utc DESC LIMIT %d OFFSET %d";
    $rows = $this->wpdb->get_results(
        $this->wpdb->prepare($listSql, ...array_merge($args, [$perPage, $offset])),
        ARRAY_A
    );
    if (!is_array($rows)) {
        $rows = [];
    }
    $items = array_values(array_map(fn (array $r) => $this->fromRow($r), $rows));
    return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
```

- [ ] **Step 2 : Test d'intégration**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Persistence\BookingRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class AdminBookingControllerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
    }

    public function test_list_requires_capability(): void
    {
        wp_set_current_user(0); // anonymous
        $r = new WP_REST_Request('GET', '/slashbooking/v1/admin/bookings');
        self::assertSame(401, rest_do_request($r)->get_status());
    }

    public function test_list_returns_paginated_results_for_admin(): void
    {
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $this->seed(3);

        $r = new WP_REST_Request('GET', '/slashbooking/v1/admin/bookings');
        $r->set_query_params(['per_page' => 2]);
        $resp = rest_do_request($r);
        self::assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        self::assertSame(3, $data['total']);
        self::assertCount(2, $data['items']);
    }

    public function test_confirm_endpoint_transitions_status(): void
    {
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $b = $this->seed(1)[0];

        $r = new WP_REST_Request('POST', '/slashbooking/v1/admin/bookings/' . $b->id() . '/confirm');
        $resp = rest_do_request($r);
        self::assertSame(200, $resp->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::CONFIRMED, $refreshed->status());
    }

    /**
     * @return list<Booking>
     */
    private function seed(int $count): array
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $out  = [];
        for ($i = 0; $i < $count; $i++) {
            $slot = new TimeSlot(
                new DateTimeImmutable('2026-06-0' . ($i + 1) . 'T08:00:00Z', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-06-0' . ($i + 1) . 'T09:30:00Z', new DateTimeZone('UTC')),
            );
            $b = Booking::createPending(
                serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
                customerName: 'C' . $i, customerEmail: 'c' . $i . '@x.fr',
                customerPhone: '0600', customerAddress: 'x',
                customerMeta: [], notes: '',
            );
            $repo->save($b);
            $out[] = $b;
        }
        return $out;
    }
}
```

- [ ] **Step 3 : Rouge**

- [ ] **Step 4 : Implémenter `AdminBookingController`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Admin\Capabilities;
use Slash\Booking\Booking\CancelBooking;
use Slash\Booking\Booking\ConfirmBooking;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Booking\RejectBooking;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AdminBookingController
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ConfirmBooking $confirm,
        private readonly RejectBooking $reject,
        private readonly CancelBooking $cancel,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/admin/bookings',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list'],
                'permission_callback' => [$this, 'permission'],
            ]
        );

        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/admin/bookings/(?P<id>\d+)/(?P<action>confirm|reject|cancel)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'act'],
                'permission_callback' => [$this, 'permission'],
                'args' => [
                    'id'     => ['type' => 'integer', 'required' => true],
                    'action' => ['type' => 'string',  'required' => true, 'enum' => ['confirm','reject','cancel']],
                ],
            ]
        );
    }

    public function permission(): bool|WP_Error
    {
        if (!current_user_can(Capabilities::MANAGE)) {
            return new WP_Error('sb_forbidden', 'Forbidden', ['status' => is_user_logged_in() ? 403 : 401]);
        }
        return true;
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $page    = (int) ($request['page']     ?? 1);
        $perPage = (int) ($request['per_page'] ?? 20);

        $filters = [
            'status'     => $request['status'] ? (string) $request['status'] : null,
            'service_id' => $request['service_id'] ? (int) $request['service_id'] : null,
            'from'       => $this->parseDate((string) ($request['from'] ?? '')),
            'to'         => $this->parseDate((string) ($request['to'] ?? '')),
        ];

        $result = $this->bookings->paginate($filters, $page, $perPage);
        $items  = array_map(static fn (Booking $b) => self::serialize($b), $result['items']);
        return new WP_REST_Response([
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    public function act(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request['id'];
        $action = (string) $request['action'];
        try {
            switch ($action) {
                case 'confirm':
                    $this->confirm->execute($id);
                    break;
                case 'reject':
                    $this->reject->execute($id);
                    break;
                case 'cancel':
                    $b = $this->bookings->findById($id);
                    if ($b === null) {
                        throw new BookingNotFound('not found');
                    }
                    $this->cancel->execute($b->publicUid());
                    break;
            }
        } catch (BookingNotFound $e) {
            return new WP_Error('sb_not_found', 'Booking not found.', ['status' => 404]);
        } catch (\DomainException $e) {
            return new WP_Error('sb_invalid_transition', $e->getMessage(), ['status' => 409]);
        }
        $refreshed = $this->bookings->findById($id);
        return new WP_REST_Response(self::serialize($refreshed));
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(?Booking $b): array
    {
        if ($b === null) {
            return [];
        }
        return [
            'id'             => $b->id(),
            'public_uid'     => $b->publicUid(),
            'service_id'     => $b->serviceId(),
            'status'         => $b->status()->value,
            'starts_at_utc'  => $b->slot()->start->format(DATE_ATOM),
            'ends_at_utc'    => $b->slot()->end->format(DATE_ATOM),
            'timezone'       => $b->timezone(),
            'customer_name'  => $b->customerName(),
            'customer_email' => $b->customerEmail(),
            'customer_phone' => $b->customerPhone(),
            'customer_address' => $b->customerAddress(),
            'notes'          => $b->notes(),
            'created_at'     => $b->createdAt()->format(DATE_ATOM),
            'updated_at'     => $b->updatedAt()->format(DATE_ATOM),
        ];
    }

    private function parseDate(string $s): ?DateTimeImmutable
    {
        if ($s === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Step 5 : Brancher dans `RestRouter`**

À la fin de `RestRouter::registerRoutes()` :

```php
$cancelUC = new \Slash\Booking\Booking\CancelBooking(
    find: fn (string $uid) => $bookings->findByPublicUid($uid),
    persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
);

(new AdminBookingController($bookings, $confirmUC, $rejectUC, $cancelUC))->registerRoutes();
```

- [ ] **Step 6 : Vert + PHPStan + commit**

```bash
composer test:integration -- --filter AdminBookingControllerTest
composer stan
git add src/Http/AdminBookingController.php src/Http/RestRouter.php src/Persistence/BookingRepository.php tests/Integration/AdminBookingControllerTest.php
git commit -m "feat(http): AdminBookingController list + confirm/reject/cancel"
```

---

## Task 19 : Notifications — `ReminderScheduler`

**Files:**
- Create: `src/Notifications/ReminderScheduler.php`
- Create: `tests/Integration/ReminderSchedulerTest.php`
- Modify: `src/Activator.php` (schedule daily cron)
- Modify: `src/Deactivator.php` (unschedule cron)
- Modify: `src/Persistence/BookingRepository.php` (`findRemindersDue`, `markReminderSent`)

Cron WP quotidien `sb_send_daily_reminders` à 10h fuseau site → sélectionne bookings `confirmed` dont `starts_at_utc ∈ [now+23h, now+25h]` et `reminder_sent_at IS NULL`, marque envoyé et émet `slashbooking/booking_reminder_due`.

- [ ] **Step 1 : Étendre `BookingRepository`**

```php
/**
 * @return list<Booking>
 */
public function findRemindersDue(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
{
    $sql = $this->wpdb->prepare(
        "SELECT * FROM {$this->table}
         WHERE status = %s
           AND reminder_sent_at IS NULL
           AND starts_at_utc >= %s
           AND starts_at_utc < %s",
        BookingStatus::CONFIRMED->value,
        $windowStart->format('Y-m-d H:i:s'),
        $windowEnd->format('Y-m-d H:i:s'),
    );
    $rows = $this->wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_map(fn (array $r) => $this->fromRow($r), $rows));
}

public function markReminderSent(int $bookingId, DateTimeImmutable $atUtc): void
{
    $this->wpdb->update(
        $this->table,
        ['reminder_sent_at' => $atUtc->format('Y-m-d H:i:s')],
        ['id' => $bookingId],
    );
}
```

- [ ] **Step 2 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Notifications\ReminderScheduler;
use Slash\Booking\Persistence\BookingRepository;
use WP_UnitTestCase;

final class ReminderSchedulerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
    }

    public function test_fires_reminder_action_for_due_bookings(): void
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $slot = new TimeSlot(
            $now->modify('+24 hours')->setTime(10, 0),
            $now->modify('+24 hours')->setTime(11, 0),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'X', customerEmail: 'x@x.fr', customerPhone: '0',
            customerAddress: '', customerMeta: [], notes: '',
        );
        $b->confirm();
        $repo->save($b);

        $fired = [];
        add_action('slashbooking/booking_reminder_due', static function (int $id) use (&$fired): void {
            $fired[] = $id;
        });

        (new ReminderScheduler($repo))->run();
        self::assertContains($b->id(), $fired);

        // Second run does not refire
        $fired = [];
        (new ReminderScheduler($repo))->run();
        self::assertSame([], $fired);
    }
}
```

- [ ] **Step 3 : Implémenter `ReminderScheduler`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Persistence\BookingRepository;

final class ReminderScheduler
{
    public const HOOK = 'sb_send_daily_reminders';

    public function __construct(private readonly BookingRepository $bookings)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function run(): void
    {
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $now->modify('+23 hours');
        $end   = $now->modify('+25 hours');

        foreach ($this->bookings->findRemindersDue($start, $end) as $booking) {
            $id = $booking->id();
            if ($id === null) {
                continue;
            }
            // mark first to be anti-doublon under cron retry
            $this->bookings->markReminderSent($id, $now);
            do_action('slashbooking/booking_reminder_due', $id);
        }
    }
}
```

- [ ] **Step 4 : Schedule dans Activator**

Dans `Activator::activate()` :

```php
if (!wp_next_scheduled(\Slash\Booking\Notifications\ReminderScheduler::HOOK)) {
    wp_schedule_event(self::tomorrowAt10Utc(), 'daily', \Slash\Booking\Notifications\ReminderScheduler::HOOK);
}
```

Ajouter helper :

```php
private static function tomorrowAt10Utc(): int
{
    $tz   = wp_timezone();
    $when = new \DateTimeImmutable('tomorrow 10:00', $tz);
    return $when->getTimestamp();
}
```

- [ ] **Step 5 : Unschedule dans Deactivator**

```php
$timestamp = wp_next_scheduled(\Slash\Booking\Notifications\ReminderScheduler::HOOK);
if ($timestamp !== false) {
    wp_unschedule_event($timestamp, \Slash\Booking\Notifications\ReminderScheduler::HOOK);
}
```

- [ ] **Step 6 : Brancher le scheduler dans `Plugin::register()`**

```php
$reminder = new \Slash\Booking\Notifications\ReminderScheduler(
    new \Slash\Booking\Persistence\BookingRepository($wpdb)
);
$reminder->register();
```

- [ ] **Step 7 : Vert + commit**

```bash
composer test:integration -- --filter ReminderSchedulerTest
composer stan
git add src/Notifications/ReminderScheduler.php src/Persistence/BookingRepository.php src/Activator.php src/Deactivator.php src/Plugin.php tests/Integration/ReminderSchedulerTest.php
git commit -m "feat(notifications): J-1 reminder cron + scheduler"
```

---

## Task 20 : Plugin — Wire `BookingNotifier` au boot

**Files:**
- Modify: `src/Plugin.php`

Instancier `BookingNotifier` à `Plugin::register()` pour qu'il s'abonne aux hooks au bon moment. (Le scheduler de Task 19 est déjà branché.)

- [ ] **Step 1 : Modifier `Plugin::register`**

Après les lignes existantes, ajouter :

```php
$signer  = new \Slash\Booking\Booking\DecisionTokenSigner((string) get_option('sb_decision_secret'));
$urls    = new \Slash\Booking\Http\UrlBuilder($signer, rest_url(self::REST_NAMESPACE));

$dispatcher = new \Slash\Booking\Notifications\MailDispatcher(
    new \Slash\Booking\Persistence\MailTemplateRepository($wpdb),
    new \Slash\Booking\Notifications\TemplateRenderer(new \Slash\Booking\Notifications\TagRegistry()),
    new \Slash\Booking\Notifications\TextBodyGenerator(),
    new \Slash\Booking\Notifications\IcsBuilder(),
);

(new \Slash\Booking\Notifications\BookingNotifier(
    $services,
    new \Slash\Booking\Persistence\BookingRepository($wpdb),
    $dispatcher,
    $urls,
))->register();
```

(Note : on garde la duplication d'instanciation avec `RestRouter` pour Plan 2 ; la consolidation DI viendra en Plan 5.)

- [ ] **Step 2 : Test rapide d'intégration**

Lancer la suite d'intégration complète :

```bash
composer test:integration
```

Aucune régression.

- [ ] **Step 3 : Commit**

```bash
git add src/Plugin.php
git commit -m "feat(plugin): boot BookingNotifier to wire mails to booking lifecycle"
```

---

## Task 21 : Activator — Seed des templates personnalisables (table vide en V1)

**Files:**
- Modify: `src/Activator.php`

Décision : on **ne pré-remplit pas** la table `wp_sb_mail_templates` à l'activation. La table reste vide jusqu'à ce que l'admin sauvegarde une override depuis l'éditeur (livré en Plan 5). Le repository retourne déjà le défaut bundlé via `getOrDefault()`. → On supprime juste l'appel placeholder `seedMailTemplates()` ajouté en Task 17.

- [ ] **Step 1 : Retirer l'appel à `seedMailTemplates`** dans `Activator::activate()`.
- [ ] **Step 2 : Lancer la suite complète**

```bash
composer test
composer test:integration
composer stan
```

- [ ] **Step 3 : Commit**

```bash
git add src/Activator.php
git commit -m "chore(activator): no template seed — repository defaults bundled"
```

---

## Task 22 : Admin — `AdminMenu`

**Files:**
- Create: `src/Admin/AdminMenu.php`
- Modify: `src/Plugin.php` (instancier et register)
- Create: `tests/Integration/AdminMenuTest.php`

Ajoute un menu top-level "SlashBooking" → page "Réservations" (render container `<div id="sb-admin-app">`).

- [ ] **Step 1 : Test**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use Slash\Booking\Activator;
use WP_UnitTestCase;

final class AdminMenuTest extends WP_UnitTestCase
{
    public function test_admin_can_see_menu(): void
    {
        Activator::activate();
        $userId = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        set_current_screen('dashboard');

        do_action('admin_menu');

        global $menu;
        $slugs = array_column($menu ?? [], 2);
        self::assertContains('slashbooking', $slugs);
    }
}
```

- [ ] **Step 2 : Rouge**

- [ ] **Step 3 : Implémenter**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            page_title: __('SlashBooking', 'slashbooking'),
            menu_title: __('SlashBooking', 'slashbooking'),
            capability: Capabilities::VIEW,
            menu_slug:  'slashbooking',
            callback:   [$this, 'render'],
            icon_url:   'dashicons-calendar-alt',
            position:   25,
        );
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('SlashBooking', 'slashbooking') . '</h1>';
        echo '<div id="sb-admin-app"></div></div>';
    }
}
```

- [ ] **Step 4 : Brancher dans `Plugin::register`**

```php
(new \Slash\Booking\Admin\AdminMenu())->register();
```

- [ ] **Step 5 : Vert + commit**

```bash
composer test:integration -- --filter AdminMenuTest
composer stan
git add src/Admin/AdminMenu.php src/Plugin.php tests/Integration/AdminMenuTest.php
git commit -m "feat(admin): top-level menu + container for React SPA"
```

---

## Task 23 : Admin — `Assets` (enqueue React SPA)

**Files:**
- Create: `src/Admin/Assets.php`
- Modify: `src/Plugin.php`

L'enqueue se fera **seulement** sur la page admin du plugin. On lit `assets/dist/admin.asset.php` (généré par `@wordpress/scripts`) pour récupérer `dependencies` + `version`. Si le bundle n'existe pas (dev), on log un warning.

- [ ] **Step 1 : Implémenter `Assets`**

```php
<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

use Slash\Booking\Plugin;

final class Assets
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_slashbooking') {
            return;
        }
        $dir = $this->plugin->pluginDir();
        $url = plugin_dir_url($this->plugin->pluginFile());
        $assetFile = $dir . '/assets/dist/admin.asset.php';
        if (!is_file($assetFile)) {
            // dev mode : pas de build
            return;
        }
        /** @var array{dependencies:array<string>, version:string} $asset */
        $asset = require $assetFile;

        wp_enqueue_script(
            'slashbooking-admin',
            $url . 'assets/dist/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_enqueue_style(
            'slashbooking-admin',
            $url . 'assets/dist/admin.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_localize_script('slashbooking-admin', 'TrinityBooking', [
            'restUrl' => esc_url_raw(rest_url(Plugin::REST_NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
```

- [ ] **Step 2 : Brancher dans `Plugin::register`**

```php
(new \Slash\Booking\Admin\Assets($this))->register();
```

- [ ] **Step 3 : Commit (test viendra avec le build npm)**

```bash
composer stan
git add src/Admin/Assets.php src/Plugin.php
git commit -m "feat(admin): enqueue React SPA on plugin admin page"
```

---

## Task 24 : Build front — `package.json` + `@wordpress/scripts`

**Files:**
- Create: `package.json`
- Create: `.gitignore` (modify to add `node_modules/`, `assets/dist/`)
- Create: `src/Admin/react-app/src/index.jsx` (placeholder)

- [ ] **Step 1 : Créer `package.json`**

```json
{
  "name": "slashbooking-admin",
  "version": "0.2.0",
  "private": true,
  "description": "Admin SPA for slashbooking",
  "scripts": {
    "build": "wp-scripts build --webpack-src-dir=src/Admin/react-app/src --output-path=assets/dist --webpack-no-externals=false src/Admin/react-app/src/index.jsx",
    "start": "wp-scripts start --webpack-src-dir=src/Admin/react-app/src --output-path=assets/dist src/Admin/react-app/src/index.jsx",
    "lint:js": "wp-scripts lint-js src/Admin/react-app/src"
  },
  "devDependencies": {
    "@wordpress/scripts": "^28.0.0"
  }
}
```

- [ ] **Step 2 : `.gitignore` — ajouter**

```
node_modules/
assets/dist/
```

- [ ] **Step 3 : `src/Admin/react-app/src/index.jsx` (placeholder qui builda)**

```jsx
import { createRoot } from '@wordpress/element';
import App from './App';

const mount = document.getElementById('sb-admin-app');
if (mount) {
    createRoot(mount).render(<App />);
}
```

- [ ] **Step 4 : `src/Admin/react-app/src/App.jsx`**

```jsx
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import BookingsPage from './BookingsPage';

export default function App() {
    return (
        <div className="sb-admin">
            <Notice status="info" isDismissible={ false }>
                { __( 'SlashBooking — dashboard V1', 'slashbooking' ) }
            </Notice>
            <BookingsPage />
        </div>
    );
}
```

- [ ] **Step 5 : `src/Admin/react-app/src/api.js`**

```js
import apiFetch from '@wordpress/api-fetch';

export function setupApi() {
    apiFetch.use( apiFetch.createNonceMiddleware( window.TrinityBooking?.nonce ) );
    apiFetch.use( apiFetch.createRootURLMiddleware( window.TrinityBooking?.restUrl + '/' ) );
}

export async function listBookings( params = {} ) {
    const qs = new URLSearchParams( params ).toString();
    return apiFetch( { path: 'admin/bookings' + ( qs ? '?' + qs : '' ) } );
}

export async function actBooking( id, action ) {
    return apiFetch( {
        path: `admin/bookings/${ id }/${ action }`,
        method: 'POST',
    } );
}
```

- [ ] **Step 6 : `npm install` + `npm run build`**

```bash
npm install
npm run build
```

Si `npm` indisponible localement → marquer la tâche comme partielle, documenter dans `src/Admin/react-app/README.md` la procédure attendue, et passer à Task 25 (les composants suivent).

- [ ] **Step 7 : Commit (sans le bundle, juste les sources)**

```bash
git add package.json package-lock.json .gitignore src/Admin/react-app/src/index.jsx src/Admin/react-app/src/App.jsx src/Admin/react-app/src/api.js
git commit -m "build(admin): wp-scripts setup + React SPA skeleton"
```

---

## Task 25 : Admin SPA — `BookingsPage` (liste + filtres)

**Files:**
- Create: `src/Admin/react-app/src/BookingsPage.jsx`
- Create: `src/Admin/react-app/src/BookingRow.jsx`
- Create: `src/Admin/react-app/src/styles.scss`

Affiche un tableau filtrable des bookings avec :
- Colonnes : Date/heure, Service, Client, Statut, Actions
- Filtres : statut (select), période (from/to)
- Actions par ligne (selon statut) : Confirmer / Refuser / Annuler

- [ ] **Step 1 : `BookingsPage.jsx`**

```jsx
import { useEffect, useState, useCallback } from '@wordpress/element';
import { SelectControl, Spinner, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { listBookings, actBooking, setupApi } from './api';
import BookingRow from './BookingRow';

setupApi();

const STATUSES = [
    { value: '',          label: __( 'Tous statuts', 'slashbooking' ) },
    { value: 'pending',   label: __( 'En attente',   'slashbooking' ) },
    { value: 'confirmed', label: __( 'Confirmés',    'slashbooking' ) },
    { value: 'rejected',  label: __( 'Refusés',      'slashbooking' ) },
    { value: 'cancelled', label: __( 'Annulés',      'slashbooking' ) },
];

export default function BookingsPage() {
    const [ items, setItems ]   = useState( [] );
    const [ total, setTotal ]   = useState( 0 );
    const [ page, setPage ]     = useState( 1 );
    const [ status, setStatus ] = useState( '' );
    const [ busy, setBusy ]     = useState( false );
    const [ error, setError ]   = useState( null );

    const load = useCallback( async () => {
        setBusy( true );
        setError( null );
        try {
            const res = await listBookings( {
                page,
                per_page: 20,
                ...( status ? { status } : {} ),
            } );
            setItems( res.items );
            setTotal( res.total );
        } catch ( e ) {
            setError( e.message || String( e ) );
        } finally {
            setBusy( false );
        }
    }, [ page, status ] );

    useEffect( () => { load(); }, [ load ] );

    const onAct = async ( id, action ) => {
        try {
            await actBooking( id, action );
            await load();
        } catch ( e ) {
            setError( e.message || String( e ) );
        }
    };

    return (
        <section className="sb-bookings">
            <div className="sb-bookings__toolbar">
                <SelectControl
                    label={ __( 'Statut', 'slashbooking' ) }
                    value={ status }
                    options={ STATUSES }
                    onChange={ ( v ) => { setPage( 1 ); setStatus( v ); } }
                />
            </div>

            { error && <Notice status="error" isDismissible onRemove={ () => setError( null ) }>{ error }</Notice> }

            { busy ? <Spinner /> : (
                <table className="widefat striped">
                    <thead>
                        <tr>
                            <th>{ __( 'Date',    'slashbooking' ) }</th>
                            <th>{ __( 'Service', 'slashbooking' ) }</th>
                            <th>{ __( 'Client',  'slashbooking' ) }</th>
                            <th>{ __( 'Statut',  'slashbooking' ) }</th>
                            <th>{ __( 'Actions', 'slashbooking' ) }</th>
                        </tr>
                    </thead>
                    <tbody>
                        { items.length === 0 && (
                            <tr><td colSpan={ 5 }>{ __( 'Aucun RDV.', 'slashbooking' ) }</td></tr>
                        ) }
                        { items.map( ( b ) => (
                            <BookingRow key={ b.id } booking={ b } onAct={ onAct } />
                        ) ) }
                    </tbody>
                </table>
            ) }

            <div className="sb-bookings__pager">
                <Button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
                    { __( 'Précédent', 'slashbooking' ) }
                </Button>
                <span> { __( 'Page', 'slashbooking' ) } { page } / { Math.max( 1, Math.ceil( total / 20 ) ) } </span>
                <Button disabled={ page * 20 >= total } onClick={ () => setPage( page + 1 ) }>
                    { __( 'Suivant', 'slashbooking' ) }
                </Button>
            </div>
        </section>
    );
}
```

- [ ] **Step 2 : `BookingRow.jsx`**

```jsx
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function fmt( iso, tz ) {
    try {
        return new Intl.DateTimeFormat( 'fr-FR', {
            dateStyle: 'medium', timeStyle: 'short', timeZone: tz,
        } ).format( new Date( iso ) );
    } catch ( e ) {
        return iso;
    }
}

const STATUS_LABELS = {
    pending:   __( 'En attente', 'slashbooking' ),
    confirmed: __( 'Confirmé',   'slashbooking' ),
    rejected:  __( 'Refusé',     'slashbooking' ),
    cancelled: __( 'Annulé',     'slashbooking' ),
    completed: __( 'Passé',      'slashbooking' ),
};

export default function BookingRow( { booking, onAct } ) {
    const s = booking.status;
    return (
        <tr>
            <td>{ fmt( booking.starts_at_utc, booking.timezone ) }</td>
            <td>#{ booking.service_id }</td>
            <td>
                <div><strong>{ booking.customer_name }</strong></div>
                <div>{ booking.customer_email } &middot; { booking.customer_phone }</div>
            </td>
            <td><span className={ `tb-status tb-status--${ s }` }>{ STATUS_LABELS[ s ] || s }</span></td>
            <td>
                { s === 'pending' && (
                    <>
                        <Button variant="primary"   onClick={ () => onAct( booking.id, 'confirm' ) }>{ __( 'Confirmer', 'slashbooking' ) }</Button>{ ' ' }
                        <Button variant="secondary" onClick={ () => onAct( booking.id, 'reject'  ) }>{ __( 'Refuser',   'slashbooking' ) }</Button>{ ' ' }
                    </>
                ) }
                { ( s === 'pending' || s === 'confirmed' ) && (
                    <Button isDestructive variant="tertiary" onClick={ () => onAct( booking.id, 'cancel' ) }>
                        { __( 'Annuler', 'slashbooking' ) }
                    </Button>
                ) }
            </td>
        </tr>
    );
}
```

- [ ] **Step 3 : `styles.scss`**

```scss
.sb-admin {
    margin-top: 16px;
}
.sb-bookings__toolbar {
    display: flex;
    gap: 16px;
    align-items: flex-end;
    margin-bottom: 16px;
}
.sb-bookings__pager {
    margin-top: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.sb-status {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    background: #eee;
}
.sb-status--pending   { background: #fef3c7; color: #92400e; }
.sb-status--confirmed { background: #dcfce7; color: #166534; }
.sb-status--rejected  { background: #fee2e2; color: #991b1b; }
.sb-status--cancelled { background: #e5e7eb; color: #374151; }
```

- [ ] **Step 4 : Importer SCSS depuis `index.jsx`**

Modifier `index.jsx` :

```jsx
import './styles.scss';
import { createRoot } from '@wordpress/element';
import App from './App';

const mount = document.getElementById('sb-admin-app');
if (mount) {
    createRoot(mount).render(<App />);
}
```

- [ ] **Step 5 : Build**

```bash
npm run build
```

Vérifier que `assets/dist/admin.js`, `admin.css`, `admin.asset.php` sont créés. (Non commit — gitignored.)

- [ ] **Step 6 : Test manuel**

1. Lancer WP en local avec `composer install` + plugin activé.
2. Aller dans le menu "SlashBooking".
3. Créer un booking via `POST /bookings`, voir la liste se charger.
4. Cliquer Confirmer → vérifier transition + e-mail (Mailhog/Mailtrap recommandé).

- [ ] **Step 7 : Commit**

```bash
git add src/Admin/react-app/src/BookingsPage.jsx src/Admin/react-app/src/BookingRow.jsx src/Admin/react-app/src/styles.scss src/Admin/react-app/src/index.jsx
git commit -m "feat(admin-spa): bookings list + per-row actions"
```

---

## Task 26 : Lint front + CI

**Files:**
- Modify: `.github/workflows/ci.yml`

Ajouter au workflow CI un job front qui :
- setup Node 20
- `npm ci`
- `npm run lint:js`
- `npm run build`

- [ ] **Step 1 : Lire l'actuel `.github/workflows/ci.yml`**, ajouter un job `frontend` après `phpunit`/`phpstan` :

```yaml
  frontend:
    name: Frontend build & lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
      - run: npm ci
      - run: npm run lint:js
      - run: npm run build
      - uses: actions/upload-artifact@v4
        with:
          name: admin-dist
          path: assets/dist/
```

- [ ] **Step 2 : Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add frontend build & lint job"
```

---

## Task 27 : Vérification finale + Spec self-review

**Files:**
- Read: spec sections 6.2, 6.3, 6.7, 7, 8, 9

- [ ] **Step 1 : Suite complète**

```bash
composer test
composer test:integration
composer stan
composer cs
npm run lint:js
npm run build
```
Tout vert.

- [ ] **Step 2 : Self-review couverture spec**

Cocher chaque exigence du Plan 2 (memory) :

- [ ] Notifications e-mail — 6 événements (Tasks 4, 9, 13, 14) ✓
- [ ] Templates HTML avec tags `{{...}}` (Tasks 5, 6) ✓
- [ ] `.ics` joint au mail confirmé (Tasks 8, 13) ✓
- [ ] Version texte auto-générée (Task 7) ✓
- [ ] Validation admin via boutons HMAC e-mail (Task 16) ✓
- [ ] Idempotence transitions (Tasks 1, 2, 3, 16) ✓
- [ ] Dashboard React liste + actions (Tasks 22-25) ✓
- [ ] Reminder J-1 cron (Task 19) ✓
- [ ] Capabilities WP custom (Task 17) ✓
- [ ] REST admin endpoints (Task 18) ✓

- [ ] **Step 3 : Mettre à jour `README.md`** — paragraphe "Statut" :

```
## Statut
- ✅ Plan 1 : Bootstrap + fondations publiques
- ✅ Plan 2 : Notifications e-mail + validation admin (HMAC + dashboard React)
- ⏸️ Plan 3 : Google OAuth + push WP → GCal
- ⏸️ Plan 4 : Webhook Google + pull GCal → WP
- ⏸️ Plan 5 : Templates editor + RGPD + i18n + packaging
```

- [ ] **Step 4 : Commit final**

```bash
git add README.md
git commit -m "docs: mark Plan 2 complete"
```

- [ ] **Step 5 : Mettre à jour la mémoire**

Mettre à jour `project_overview.md` (table des plans : Plan 2 → ✅ Terminé YYYY-MM-DD).

---

## Quickstart (V1 minimal après Plan 2)

```bash
# Backend
composer install
# Front
npm install && npm run build
# Tests
composer test && composer test:integration && composer stan
```

Activer le plugin, créer un booking via `POST /wp-json/slashbooking/v1/bookings`, vérifier l'e-mail client + admin, cliquer "Confirmer" dans l'e-mail, vérifier la transition + l'e-mail confirmé avec `.ics`, vérifier dans le dashboard.

---

## Definition of Done — Plan 2

- Tous tests unit + integration verts (skip propre si wp-phpunit non installée).
- PHPStan niveau 8 : 0 erreur.
- PHPCS : 0 erreur.
- Front : `npm run build` produit `assets/dist/admin.{js,css,asset.php}` sans erreur ; `npm run lint:js` clean.
- Côté manuel : créer un booking → 2 e-mails partent ; clic Confirmer → transition + e-mail `.ics` ; clic Refuser → transition + e-mail "désolé" ; dashboard admin affiche la liste avec filtres et actions ; reminder J-1 envoie un mail si on déclenche `wp cron event run sb_send_daily_reminders`.

---

## Self-review (effectué par l'auteur du plan)

**Spec coverage :**
- 6.2 (validation admin e-mail) → Task 16 ✓
- 6.3 (validation admin dashboard) → Tasks 18, 22-25 ✓
- 6.4 (cancel client) → déjà Plan 1 + e-mail cancelled.client par Task 14 ✓
- 6.7 (reminder J-1) → Task 19 ✓
- 7 (REST admin endpoints) → Tasks 16, 18 ✓
- 8 (templates e-mail : 6 events, tags, raw, text fallback, defaults, repo) → Tasks 4-11, 13 ✓ (éditeur CodeMirror reporté Plan 5)
- 9.1/9.3 (caps custom, HMAC) → Tasks 17, 16 ✓

**Placeholder scan :** aucune section TBD ; chaque step a son code complet ; conventions de naming cohérentes entre tasks (`EventKey`, `BookingContext`, `MailDispatcher`, `BookingNotifier`).

**Type consistency :**
- `EventKey::PENDING_CLIENT`/`PENDING_ADMIN`/etc utilisés à l'identique dans `DefaultTemplates`, `MailDispatcher`, `BookingNotifier`, `MailTemplateRepository`.
- `BookingContext::fromBooking(Booking, Service, array $extra)` — appelée avec la même signature dans `BookingNotifier`, `MailDispatcherTest`.
- `UrlBuilder::cancelUrl($publicUid, $exp)` / `decisionUrl($id, $action, $exp)` — appelés à l'identique dans `BookingNotifier` et test.
- `BookingRepository::paginate(filters, page, perPage)` retourne `{items,total,page,per_page}` — clé `per_page` (snake_case) utilisée côté React.

**Hooks WP nommés :**
- `slashbooking/booking_created`
- `slashbooking/booking_confirmed`
- `slashbooking/booking_rejected`
- `slashbooking/booking_cancelled`
- `slashbooking/booking_reminder_due`

Utilisés exactement à l'identique dans les use cases (Task 15) et `BookingNotifier::register` (Task 14).
