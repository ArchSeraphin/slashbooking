# trinity-booking — Plan 1 : Bootstrap & Fondations publiques

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mettre en place le squelette du plugin WordPress `trinity-booking` et permettre à un visiteur de réserver un créneau depuis un shortcode — le RDV est enregistré en base avec statut `pending`. Pas encore d'e-mails, ni d'admin, ni de Google.

**Architecture:** Plugin WordPress modulaire PSR-4 (Composer). Couches strictes : `Domain/` (entités pures), `Persistence/` (wrappers `$wpdb`), `Availability/` (calcul de créneaux), `Booking/` (cas d'usage), `Http/` (REST controllers), `PublicFront/` (shortcode + widget JS). Tests PHPUnit + Brain Monkey en unit, wp-phpunit en intégration.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Composer, PSR-4, PHPUnit 10, Brain Monkey, PHPStan niveau 8, PHPCS WordPress-Extra, FullCalendar 6 (front), JS vanilla.

**Spec source:** `docs/superpowers/specs/2026-05-19-trinity-booking-design.md`

---

## Préambule — Concepts clés pour l'ingénieur

Avant d'attaquer les tâches :

1. **PSR-4 dans WordPress** : on évite les anti-patterns WP historiques (fichiers all-in-one). On namespace tout sous `Trinity\Booking\…` et on autoload via Composer. Le fichier d'entrée plugin ne contient quasi rien.

2. **Tests sans bootstrap WP** : pour les unités du dossier `Domain/`, on ne charge pas WordPress du tout. On utilise [Brain Monkey](https://brain-wp.github.io/BrainMonkey/) seulement pour mocker les fonctions WP (`__()`, `apply_filters`, etc.) là où elles apparaissent. Domain entities sont 100 % PHP pur — pas une seule fonction WP.

3. **TDD strict** : test rouge → code minimal → test vert → commit. Si une tâche n'a pas de test, c'est une erreur — relire le plan.

4. **Fuseaux** : tout en base est en UTC (`starts_at_utc`, `ends_at_utc`). On convertit à l'affichage avec `wp_date()`. Côté domaine, on travaille avec `DateTimeImmutable` en UTC.

5. **Migrator versionné** : option WP `tb_db_version` stocke la version courante du schéma. À l'activation et à chaque chargement, on compare et on applique les migrations manquantes.

6. **HMAC pour les URLs publiques sécurisées** : `hash_hmac('sha256', "$booking_id|$action|$exp", $secret)`. Secret généré à l'activation, stocké dans option WP `tb_decision_secret`.

7. **REST namespace** : `trinity-booking/v1`. Tous les endpoints publics utilisent un nonce WP. Les endpoints à HMAC (decide, cancel) vérifient la signature dans le `permission_callback`.

---

## File Structure (Plan 1 scope)

```
plugins-booking/
├── trinity-booking.php                # entry point (~30 lignes)
├── composer.json
├── composer.lock
├── .gitignore
├── .gitattributes
├── phpunit.xml
├── phpstan.neon
├── phpcs.xml.dist
├── .github/workflows/ci.yml
├── README.md
├── uninstall.php                      # vide pour l'instant
├── src/
│   ├── Plugin.php                     # bootstrap + DI container léger
│   ├── Activator.php
│   ├── Deactivator.php
│   ├── Domain/
│   │   ├── BookingStatus.php          # enum
│   │   ├── TimeSlot.php               # VO
│   │   ├── Service.php                # entité
│   │   ├── Booking.php                # aggregate root
│   │   └── BusyBlock.php              # entité (vide en Plan 1, structure prête)
│   ├── Persistence/
│   │   ├── Migrator.php
│   │   ├── ServiceRepository.php
│   │   ├── BookingRepository.php
│   │   └── BusyBlockRepository.php
│   ├── Availability/
│   │   ├── SlotGenerator.php
│   │   └── AvailabilityCalculator.php
│   ├── Booking/
│   │   ├── CreateBooking.php
│   │   ├── CancelBooking.php
│   │   ├── DecisionTokenSigner.php
│   │   └── Exceptions/
│   │       ├── SlotUnavailable.php
│   │       ├── InvalidBookingInput.php
│   │       └── BookingNotFound.php
│   ├── Http/
│   │   ├── RestRouter.php             # enregistre tous les endpoints
│   │   ├── PublicBookingController.php
│   │   └── PublicCancelController.php
│   └── PublicFront/
│       ├── Shortcode.php
│       └── assets/
│           ├── booking.js
│           └── booking.css
├── assets/dist/                        # build artefacts (non commit)
├── languages/
│   └── .gitkeep
└── tests/
    ├── bootstrap.php
    ├── Unit/
    │   ├── Domain/
    │   │   ├── BookingTest.php
    │   │   ├── ServiceTest.php
    │   │   └── TimeSlotTest.php
    │   ├── Availability/
    │   │   ├── SlotGeneratorTest.php
    │   │   └── AvailabilityCalculatorTest.php
    │   └── Booking/
    │       ├── DecisionTokenSignerTest.php
    │       └── CreateBookingTest.php
    └── Integration/
        ├── bootstrap-wp.php
        ├── MigratorTest.php
        ├── ServiceRepositoryTest.php
        ├── BookingRepositoryTest.php
        └── PublicBookingControllerTest.php
```

---

## Workflow attendu pour chaque tâche

1. Lire entièrement la tâche avant de coder.
2. Écrire le(s) test(s) **avant** le code.
3. Lancer le test → il doit échouer pour la bonne raison.
4. Implémenter le minimum.
5. Lancer le test → vert.
6. Lancer la suite complète pour vérifier qu'on ne casse rien.
7. Commit avec message conventional.

---

## Task 1 : Initialiser le repo Git et le squelette de fichiers

**Files:**
- Create: `.gitignore`
- Create: `.gitattributes`
- Create: `README.md`

- [ ] **Step 1: Initialiser git**

```bash
cd /Users/seraphin/Library/CloudStorage/SynologyDrive/02_Trinity/Projet/github/plugins-booking
git init -b main
```

- [ ] **Step 2: Écrire `.gitignore`**

```gitignore
# WordPress
/vendor/
/node_modules/
/assets/dist/
*.log

# OS
.DS_Store
Thumbs.db

# IDE
.idea/
.vscode/
*.iml

# Brainstorming companion
/.superpowers/

# Tests
.phpunit.result.cache
coverage/

# Build artefacts
*.zip
```

- [ ] **Step 3: Écrire `.gitattributes`**

```gitattributes
# Files to exclude from git archive (release ZIP)
/.github          export-ignore
/tests            export-ignore
/docs             export-ignore
/.superpowers     export-ignore
/phpstan.neon     export-ignore
/phpcs.xml.dist   export-ignore
/phpunit.xml      export-ignore
/.gitattributes   export-ignore
/.gitignore       export-ignore

# Line endings
*.php             text eol=lf
*.md              text eol=lf
*.json            text eol=lf
*.yml             text eol=lf
```

- [ ] **Step 4: Écrire `README.md`**

```markdown
# trinity-booking

Plugin WordPress de prise de rendez-vous commerciaux pour services solaires (photovoltaïque) et IRVE (borne de recharge), avec synchronisation bidirectionnelle Google Calendar.

## Statut

En développement actif — voir `docs/superpowers/specs/` et `docs/superpowers/plans/`.

## Pré-requis

- PHP 8.1+
- WordPress 6.5+
- Composer (dev only — le ZIP de release inclut `vendor/`)

## Installation (dev)

```bash
composer install
```

## Tests

```bash
composer test           # unit
composer test:integration   # nécessite WP test suite
composer stan           # PHPStan niveau 8
composer cs             # PHPCS
```

## Licence

GPL v2 or later
```

- [ ] **Step 5: Commit**

```bash
git add .gitignore .gitattributes README.md
git commit -m "chore: initialize repository skeleton"
```

---

## Task 2 : Initialiser Composer et l'autoloader PSR-4

**Files:**
- Create: `composer.json`

- [ ] **Step 1: Écrire `composer.json`**

```json
{
  "name": "trinity/booking",
  "description": "WordPress booking plugin for photovoltaic and EV-charging appointments with bidirectional Google Calendar sync",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "brain/monkey": "^2.6",
    "phpstan/phpstan": "^1.10",
    "szepeviktor/phpstan-wordpress": "^1.3",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "Trinity\\Booking\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Trinity\\Booking\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration",
    "stan": "phpstan analyse --memory-limit=512M",
    "cs": "phpcs",
    "cs:fix": "phpcbf"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "sort-packages": true
  },
  "minimum-stability": "stable"
}
```

- [ ] **Step 2: Installer**

Run: `composer install`
Expected: dossier `vendor/` créé, `composer.lock` généré, pas d'erreur.

- [ ] **Step 3: Vérifier l'autoload PSR-4**

```bash
composer dump-autoload --classmap-authoritative
```
Expected: `Generating optimized autoload files`.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add composer config with PSR-4 autoload and dev tooling"
```

---

## Task 3 : Configurer PHPUnit avec deux suites (unit/integration)

**Files:**
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Écrire `tests/bootstrap.php`** (bootstrap unit — Brain Monkey, pas de WP)

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 2: Écrire `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnRisky="true"
    failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Créer un test sentinel pour vérifier le runner**

Create: `tests/Unit/SentinelTest.php`

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SentinelTest extends TestCase
{
    public function test_runner_is_alive(): void
    {
        self::assertTrue(true);
    }
}
```

- [ ] **Step 4: Lancer**

Run: `composer test`
Expected: 1 test, 1 assertion, OK.

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml tests/bootstrap.php tests/Unit/SentinelTest.php
git commit -m "chore: configure PHPUnit with unit/integration suites"
```

---

## Task 4 : Configurer PHPStan et PHPCS

**Files:**
- Create: `phpstan.neon`
- Create: `phpcs.xml.dist`

- [ ] **Step 1: Écrire `phpstan.neon`**

```neon
includes:
  - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
  level: 8
  paths:
    - src
  bootstrapFiles:
    - tests/bootstrap.php
  ignoreErrors:
    # WordPress stubs sometimes lack precise types; we tighten via PHPDoc
```

- [ ] **Step 2: Écrire `phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="trinity-booking">
    <description>Coding standards for trinity-booking</description>
    <file>src</file>
    <file>tests</file>
    <arg name="basepath" value="." />
    <arg name="colors" />
    <arg name="parallel" value="4" />
    <arg name="extensions" value="php" />

    <rule ref="WordPress-Extra">
        <exclude name="WordPress.Files.FileName" />
        <exclude name="Universal.Files.SeparateFunctionsFromOO" />
        <exclude name="Generic.Commenting.DocComment.MissingShort" />
    </rule>

    <rule ref="PHPCompatibilityWP" />
    <config name="testVersion" value="8.1-" />
    <config name="minimum_wp_version" value="6.5" />

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="trinity-booking" />
            </property>
        </properties>
    </rule>
</ruleset>
```

- [ ] **Step 3: Lancer PHPStan**

Run: `composer stan`
Expected: `[OK] No errors` (puisque `src/` est vide).

- [ ] **Step 4: Lancer PHPCS**

Run: `composer cs`
Expected: pas d'erreur (rien à scanner sauf `tests/Unit/SentinelTest.php` qui est conforme).

- [ ] **Step 5: Commit**

```bash
git add phpstan.neon phpcs.xml.dist
git commit -m "chore: configure PHPStan level 8 and WordPress coding standards"
```

---

## Task 5 : GitHub Actions CI minimale

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Écrire le workflow**

```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: [ '8.1', '8.2', '8.3' ]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
          tools: composer:v2
      - name: Validate composer.json
        run: composer validate --strict
      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ matrix.php }}-${{ hashFiles('composer.lock') }}
      - name: Install
        run: composer install --no-progress --prefer-dist
      - name: PHPUnit (unit)
        run: composer test
      - name: PHPStan
        run: composer stan
      - name: PHPCS
        run: composer cs
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "chore: add CI workflow for PHP 8.1/8.2/8.3"
```

---

## Task 6 : Plugin entry file + classe Plugin (DI container léger)

**Files:**
- Create: `trinity-booking.php`
- Create: `src/Plugin.php`
- Create: `uninstall.php`

- [ ] **Step 1: Écrire un test pour `Plugin::version()`**

Create: `tests/Unit/PluginTest.php`

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Plugin;

final class PluginTest extends TestCase
{
    public function test_version_returns_non_empty_string(): void
    {
        self::assertNotEmpty(Plugin::version());
    }

    public function test_text_domain_constant(): void
    {
        self::assertSame('trinity-booking', Plugin::TEXT_DOMAIN);
    }
}
```

- [ ] **Step 2: Lancer → échec**

Run: `composer test -- --filter PluginTest`
Expected: échec — classe `Plugin` n'existe pas.

- [ ] **Step 3: Écrire `src/Plugin.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking;

final class Plugin
{
    public const VERSION = '0.1.0-dev';
    public const TEXT_DOMAIN = 'trinity-booking';
    public const DB_VERSION = 1;
    public const REST_NAMESPACE = 'trinity-booking/v1';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private string $pluginFile;

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pluginFile);
            self::$instance->register();
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Plugin not booted');
        }
        return self::$instance;
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    public function pluginDir(): string
    {
        return \dirname($this->pluginFile);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param T $instance
     */
    public function set(string $id, object $instance): void
    {
        $this->services[$id] = $instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service not registered: {$id}");
        }
        /** @var T */
        return $this->services[$id];
    }

    private function register(): void
    {
        // Hooks are registered later (Task 7+)
    }
}
```

- [ ] **Step 4: Lancer → vert**

Run: `composer test -- --filter PluginTest`
Expected: 2 tests OK.

- [ ] **Step 5: Écrire l'entry file `trinity-booking.php`**

```php
<?php
/**
 * Plugin Name:       Trinity Booking
 * Plugin URI:        https://trinity.example/
 * Description:       Online appointment booking with Google Calendar sync.
 * Version:           0.1.0-dev
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Trinity
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trinity-booking
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    return;
}
require_once $autoload;

\Trinity\Booking\Plugin::boot(__FILE__);
```

- [ ] **Step 6: Écrire `uninstall.php` (placeholder)**

```php
<?php
/**
 * Trinity Booking — Uninstall.
 *
 * Currently a no-op. Data wipe is opt-in and handled in a later plan.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;
```

- [ ] **Step 7: Commit**

```bash
git add trinity-booking.php uninstall.php src/Plugin.php tests/Unit/PluginTest.php
git commit -m "feat: bootstrap plugin entry and DI container"
```

---

## Task 7 : Domain — Enum `BookingStatus`

**Files:**
- Create: `src/Domain/BookingStatus.php`
- Create: `tests/Unit/Domain/BookingStatusTest.php`

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\BookingStatus;

final class BookingStatusTest extends TestCase
{
    public function test_blocks_slot_for_pending_and_confirmed(): void
    {
        self::assertTrue(BookingStatus::PENDING->blocksSlot());
        self::assertTrue(BookingStatus::CONFIRMED->blocksSlot());
        self::assertFalse(BookingStatus::REJECTED->blocksSlot());
        self::assertFalse(BookingStatus::CANCELLED->blocksSlot());
        self::assertFalse(BookingStatus::COMPLETED->blocksSlot());
    }

    public function test_from_string_round_trip(): void
    {
        foreach (BookingStatus::cases() as $case) {
            self::assertSame($case, BookingStatus::from($case->value));
        }
    }
}
```

- [ ] **Step 2: Run → fail**

Run: `composer test -- --filter BookingStatusTest`
Expected: classe inconnue.

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

enum BookingStatus: string
{
    case PENDING   = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED  = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    public function blocksSlot(): bool
    {
        return match ($this) {
            self::PENDING, self::CONFIRMED => true,
            self::REJECTED, self::CANCELLED, self::COMPLETED => false,
        };
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test -- --filter BookingStatusTest`
Expected: 2 OK.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/BookingStatus.php tests/Unit/Domain/BookingStatusTest.php
git commit -m "feat(domain): add BookingStatus enum with blocksSlot logic"
```

---

## Task 8 : Domain — Value object `TimeSlot`

**Files:**
- Create: `src/Domain/TimeSlot.php`
- Create: `tests/Unit/Domain/TimeSlotTest.php`

`TimeSlot` représente une plage immuable en UTC. Invariants : `start < end`. Pas de dépendance WP.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class TimeSlotTest extends TestCase
{
    private function utc(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function test_constructs_valid_slot(): void
    {
        $slot = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        self::assertSame(90, $slot->durationMinutes());
    }

    public function test_rejects_inverted_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TimeSlot($this->utc('2026-06-01T09:30:00Z'), $this->utc('2026-06-01T08:00:00Z'));
    }

    public function test_rejects_non_utc_dates(): void
    {
        $start = new DateTimeImmutable('2026-06-01T08:00:00', new DateTimeZone('Europe/Paris'));
        $end   = new DateTimeImmutable('2026-06-01T09:30:00', new DateTimeZone('Europe/Paris'));
        $this->expectException(\InvalidArgumentException::class);
        new TimeSlot($start, $end);
    }

    public function test_overlaps_detection(): void
    {
        $a = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:00:00Z'));
        $b = new TimeSlot($this->utc('2026-06-01T08:30:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $c = new TimeSlot($this->utc('2026-06-01T09:00:00Z'), $this->utc('2026-06-01T10:00:00Z'));
        self::assertTrue($a->overlaps($b));
        self::assertFalse($a->overlaps($c)); // tangent, exclusif à droite
    }

    public function test_expand_returns_new_slot(): void
    {
        $slot = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $expanded = $slot->expand(30, 30);
        self::assertSame('2026-06-01T07:30:00+00:00', $expanded->start->format('c'));
        self::assertSame('2026-06-01T10:00:00+00:00', $expanded->end->format('c'));
    }
}
```

- [ ] **Step 2: Run → fail**

Run: `composer test -- --filter TimeSlotTest`

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class TimeSlot
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($start->getTimezone()->getName() !== 'UTC' || $end->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('TimeSlot dates must be UTC.');
        }
        if ($start >= $end) {
            throw new InvalidArgumentException('TimeSlot start must be before end.');
        }
    }

    public function durationMinutes(): int
    {
        return (int) (($this->end->getTimestamp() - $this->start->getTimestamp()) / 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function expand(int $beforeMinutes, int $afterMinutes): self
    {
        return new self(
            $this->start->modify(sprintf('-%d minutes', $beforeMinutes)),
            $this->end->modify(sprintf('+%d minutes', $afterMinutes)),
        );
    }

    /**
     * @return array{start:string,end:string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->format(DATE_ATOM),
            'end'   => $this->end->format(DATE_ATOM),
        ];
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test -- --filter TimeSlotTest`
Expected: 5 OK.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/TimeSlot.php tests/Unit/Domain/TimeSlotTest.php
git commit -m "feat(domain): add TimeSlot value object with overlap and expand"
```

---

## Task 9 : Domain — Entité `Service`

**Files:**
- Create: `src/Domain/Service.php`
- Create: `tests/Unit/Domain/ServiceTest.php`

Un `Service` porte sa configuration (durée, buffers, horaires hebdomadaires en JSON). On garde un constructeur explicite + factory `fromRow()` pour la persistance.

Format `weekly_hours` : `array<int, list<array{open:string, close:string}>>` indexé par jour ISO (1 = lundi, 7 = dimanche). Heures `HH:MM` en local au fuseau WP.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Service;

final class ServiceTest extends TestCase
{
    public function test_constructs_and_exposes_attributes(): void
    {
        $svc = new Service(
            id: 1,
            slug: 'pv',
            name: 'Photovoltaïque',
            durationMin: 90,
            bufferBeforeMin: 0,
            bufferAfterMin: 30,
            minLeadTimeHours: 24,
            maxHorizonDays: 60,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '18:00']],
                6 => [['open' => '09:00', 'close' => '13:00']],
            ],
            active: true,
            color: '#f59e0b',
        );
        self::assertSame('pv', $svc->slug);
        self::assertSame(90, $svc->durationMin);
        self::assertTrue($svc->isActive());
    }

    public function test_from_row_round_trip(): void
    {
        $row = [
            'id' => 2,
            'slug' => 'irve',
            'name' => 'Borne de recharge',
            'duration_min' => 45,
            'buffer_before_min' => 0,
            'buffer_after_min' => 30,
            'min_lead_time_hours' => 24,
            'max_horizon_days' => 60,
            'color' => '#10b981',
            'active' => 1,
            'sort_order' => 2,
            'settings' => json_encode(['weekly_hours' => [
                1 => [['open' => '09:00', 'close' => '18:00']],
            ]]),
        ];
        $svc = Service::fromRow($row);
        self::assertSame('irve', $svc->slug);
        self::assertSame([['open' => '09:00', 'close' => '18:00']], $svc->weeklyHoursForIsoDay(1));
        self::assertSame([], $svc->weeklyHoursForIsoDay(7));
    }

    public function test_rejects_negative_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Service(
            id: 1, slug: 'x', name: 'X',
            durationMin: 0,
            bufferBeforeMin: 0, bufferAfterMin: 0,
            minLeadTimeHours: 0, maxHorizonDays: 1,
            weeklyHours: [], active: true, color: '#000000',
        );
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use InvalidArgumentException;

/**
 * @phpstan-type WeeklyHours array<int, list<array{open:string, close:string}>>
 */
final readonly class Service
{
    /**
     * @param WeeklyHours $weeklyHours
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $name,
        public int $durationMin,
        public int $bufferBeforeMin,
        public int $bufferAfterMin,
        public int $minLeadTimeHours,
        public int $maxHorizonDays,
        public array $weeklyHours,
        public bool $active,
        public string $color,
    ) {
        if ($durationMin < 1) {
            throw new InvalidArgumentException('Service duration must be >= 1 minute.');
        }
        if ($maxHorizonDays < 1) {
            throw new InvalidArgumentException('Service horizon must be >= 1 day.');
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new InvalidArgumentException('Service slug must be lowercase kebab-case.');
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return list<array{open:string, close:string}>
     */
    public function weeklyHoursForIsoDay(int $isoDay): array
    {
        return $this->weeklyHours[$isoDay] ?? [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $settings = [];
        if (isset($row['settings']) && is_string($row['settings'])) {
            $decoded = json_decode($row['settings'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }
        /** @var WeeklyHours $weekly */
        $weekly = $settings['weekly_hours'] ?? [];

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            durationMin: (int) $row['duration_min'],
            bufferBeforeMin: (int) ($row['buffer_before_min'] ?? 0),
            bufferAfterMin: (int) ($row['buffer_after_min'] ?? 0),
            minLeadTimeHours: (int) ($row['min_lead_time_hours'] ?? 24),
            maxHorizonDays: (int) ($row['max_horizon_days'] ?? 60),
            weeklyHours: $weekly,
            active: ((int) ($row['active'] ?? 1)) === 1,
            color: (string) ($row['color'] ?? '#0ea5e9'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'duration_min' => $this->durationMin,
            'buffer_before_min' => $this->bufferBeforeMin,
            'buffer_after_min' => $this->bufferAfterMin,
            'min_lead_time_hours' => $this->minLeadTimeHours,
            'max_horizon_days' => $this->maxHorizonDays,
            'color' => $this->color,
            'active' => $this->active ? 1 : 0,
            'settings' => json_encode(['weekly_hours' => $this->weeklyHours], JSON_UNESCAPED_UNICODE),
        ];
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test -- --filter ServiceTest`

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Service.php tests/Unit/Domain/ServiceTest.php
git commit -m "feat(domain): add Service entity with row factory"
```

---

## Task 10 : Domain — Entité `Booking`

**Files:**
- Create: `src/Domain/Booking.php`
- Create: `tests/Unit/Domain/BookingTest.php`

`Booking` est mutable (statut + timestamps évoluent), mais on encadre les transitions de statut.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class BookingTest extends TestCase
{
    private function slot(): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00', new DateTimeZone('UTC')),
        );
    }

    public function test_new_booking_is_pending(): void
    {
        $b = Booking::createPending(
            serviceId: 1,
            slot: $this->slot(),
            timezone: 'Europe/Paris',
            customerName: 'Jean Test',
            customerEmail: 'jean@test.fr',
            customerPhone: '0600000000',
            customerAddress: '1 rue X, 75001 Paris',
            customerMeta: ['housing' => 'house'],
            notes: 'Maison récente',
        );
        self::assertSame(BookingStatus::PENDING, $b->status());
        self::assertNotEmpty($b->publicUid());
        self::assertSame(1, $b->serviceId());
    }

    public function test_confirm_transitions_only_from_pending(): void
    {
        $b = $this->makePending();
        $b->confirm();
        self::assertSame(BookingStatus::CONFIRMED, $b->status());

        $this->expectException(\DomainException::class);
        $b->confirm();
    }

    public function test_reject_only_from_pending(): void
    {
        $b = $this->makePending();
        $b->reject();
        self::assertSame(BookingStatus::REJECTED, $b->status());

        $this->expectException(\DomainException::class);
        $b->reject();
    }

    public function test_cancel_from_pending_or_confirmed(): void
    {
        $b1 = $this->makePending();
        $b1->cancel();
        self::assertSame(BookingStatus::CANCELLED, $b1->status());

        $b2 = $this->makePending();
        $b2->confirm();
        $b2->cancel();
        self::assertSame(BookingStatus::CANCELLED, $b2->status());

        $this->expectException(\DomainException::class);
        $b2->cancel();
    }

    public function test_mark_reminder_sent_once(): void
    {
        $b = $this->makePending();
        $b->confirm();
        self::assertNull($b->reminderSentAt());
        $b->markReminderSent(new DateTimeImmutable('2026-05-31T10:00:00', new DateTimeZone('UTC')));
        self::assertNotNull($b->reminderSentAt());

        $this->expectException(\DomainException::class);
        $b->markReminderSent(new DateTimeImmutable('2026-05-31T11:00:00', new DateTimeZone('UTC')));
    }

    private function makePending(): Booking
    {
        return Booking::createPending(
            serviceId: 1,
            slot: $this->slot(),
            timezone: 'Europe/Paris',
            customerName: 'Jean Test',
            customerEmail: 'jean@test.fr',
            customerPhone: '0600000000',
            customerAddress: '1 rue X',
            customerMeta: [],
            notes: '',
        );
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class Booking
{
    /**
     * @param array<string, mixed> $customerMeta
     */
    private function __construct(
        private ?int $id,
        private readonly string $publicUid,
        private readonly int $serviceId,
        private BookingStatus $status,
        private readonly TimeSlot $slot,
        private readonly string $timezone,
        private readonly string $customerName,
        private readonly string $customerEmail,
        private readonly string $customerPhone,
        private readonly string $customerAddress,
        private readonly array $customerMeta,
        private readonly string $notes,
        private ?string $googleEventId,
        private ?string $googleEventEtag,
        private ?string $decisionTokenHash,
        private ?DateTimeImmutable $reminderSentAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $customerMeta
     */
    public static function createPending(
        int $serviceId,
        TimeSlot $slot,
        string $timezone,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        string $customerAddress,
        array $customerMeta,
        string $notes,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: null,
            publicUid: self::generateUuid(),
            serviceId: $serviceId,
            status: BookingStatus::PENDING,
            slot: $slot,
            timezone: $timezone,
            customerName: $customerName,
            customerEmail: $customerEmail,
            customerPhone: $customerPhone,
            customerAddress: $customerAddress,
            customerMeta: $customerMeta,
            notes: $notes,
            googleEventId: null,
            googleEventEtag: null,
            decisionTokenHash: null,
            reminderSentAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function confirm(): void
    {
        $this->mustBe(BookingStatus::PENDING, 'confirm');
        $this->status = BookingStatus::CONFIRMED;
        $this->touch();
    }

    public function reject(): void
    {
        $this->mustBe(BookingStatus::PENDING, 'reject');
        $this->status = BookingStatus::REJECTED;
        $this->touch();
    }

    public function cancel(): void
    {
        if (!in_array($this->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)) {
            throw new DomainException("Cannot cancel from status {$this->status->value}");
        }
        $this->status = BookingStatus::CANCELLED;
        $this->touch();
    }

    public function markReminderSent(DateTimeImmutable $at): void
    {
        if ($this->reminderSentAt !== null) {
            throw new DomainException('Reminder already sent.');
        }
        $this->reminderSentAt = $at;
        $this->touch();
    }

    public function setGoogleEvent(string $eventId, string $etag): void
    {
        $this->googleEventId = $eventId;
        $this->googleEventEtag = $etag;
        $this->touch();
    }

    public function setDecisionTokenHash(string $hash): void
    {
        $this->decisionTokenHash = $hash;
        $this->touch();
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new DomainException('Booking id already assigned.');
        }
        $this->id = $id;
    }

    public function id(): ?int { return $this->id; }
    public function publicUid(): string { return $this->publicUid; }
    public function serviceId(): int { return $this->serviceId; }
    public function status(): BookingStatus { return $this->status; }
    public function slot(): TimeSlot { return $this->slot; }
    public function timezone(): string { return $this->timezone; }
    public function customerName(): string { return $this->customerName; }
    public function customerEmail(): string { return $this->customerEmail; }
    public function customerPhone(): string { return $this->customerPhone; }
    public function customerAddress(): string { return $this->customerAddress; }
    /** @return array<string, mixed> */
    public function customerMeta(): array { return $this->customerMeta; }
    public function notes(): string { return $this->notes; }
    public function googleEventId(): ?string { return $this->googleEventId; }
    public function googleEventEtag(): ?string { return $this->googleEventEtag; }
    public function decisionTokenHash(): ?string { return $this->decisionTokenHash; }
    public function reminderSentAt(): ?DateTimeImmutable { return $this->reminderSentAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function mustBe(BookingStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw new DomainException("Cannot {$action} from status {$this->status->value}");
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test -- --filter BookingTest`

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Booking.php tests/Unit/Domain/BookingTest.php
git commit -m "feat(domain): add Booking aggregate with status transitions"
```

---

## Task 11 : Domain — Entité `BusyBlock` (squelette)

**Files:**
- Create: `src/Domain/BusyBlock.php`

Pas de test dédié — sera testé via le repository en Plan 3 (sync Google). On pose juste la structure pour que `AvailabilityCalculator` puisse la consommer dès maintenant.

- [ ] **Step 1: Implémentation directe**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

final readonly class BusyBlock
{
    public function __construct(
        public ?int $id,
        public string $source,            // 'google' | 'manual'
        public string $sourceId,
        public ?int $googleAccountId,
        public TimeSlot $slot,
        public string $summary,
    ) {
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Domain/BusyBlock.php
git commit -m "feat(domain): add BusyBlock skeleton entity"
```

---

## Task 12 : Persistence — `Migrator` et schéma de base

**Files:**
- Create: `src/Persistence/Migrator.php`
- Create: `tests/Integration/MigratorTest.php`
- Create: `tests/Integration/bootstrap-wp.php`

L'intégration WP nécessite la WP test suite. On documente la mise en place, et on rend les tests skippables si la suite n'est pas dispo.

### Pré-requis : installer la WP test suite (une seule fois)

```bash
# Hors du repo, dans un dossier de travail
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
# (Le script standard de WP-CLI. Si pas disponible, suivre :
#  https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/ )
```

On stockera le chemin de la suite dans `WP_TESTS_DIR` (env var).

- [ ] **Step 1: Écrire `tests/Integration/bootstrap-wp.php`**

```php
<?php
declare(strict_types=1);

$wp_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (!is_dir($wp_tests_dir) || !is_file($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "\033[33mWP test suite not found at {$wp_tests_dir}. Skipping integration tests.\033[0m\n");
    exit(0);
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/trinity-booking.php';
});

require $wp_tests_dir . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
```

- [ ] **Step 2: Mettre à jour `phpunit.xml` pour pointer integration au bon bootstrap**

Modify: `phpunit.xml` — ajouter un `phpunit:Integration` config :

Replace whole `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnRisky="true"
    failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <ini name="error_reporting" value="-1" />
    </php>
</phpunit>
```

Et adapter `composer.json` scripts :
```json
"test": "phpunit --testsuite unit --bootstrap tests/bootstrap.php",
"test:integration": "phpunit --testsuite integration --bootstrap tests/Integration/bootstrap-wp.php"
```

- [ ] **Step 3: Test d'intégration du Migrator**

Create: `tests/Integration/MigratorTest.php`

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Persistence\Migrator;
use Trinity\Booking\Plugin;

final class MigratorTest extends WP_UnitTestCase
{
    public function test_creates_all_tables(): void
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $migrator->migrate();

        $expected = [
            $wpdb->prefix . 'tb_services',
            $wpdb->prefix . 'tb_bookings',
            $wpdb->prefix . 'tb_busy_blocks',
            $wpdb->prefix . 'tb_google_accounts',
            $wpdb->prefix . 'tb_sync_log',
            $wpdb->prefix . 'tb_mail_templates',
        ];

        foreach ($expected as $table) {
            $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            self::assertSame($table, $result, "Missing table: {$table}");
        }
    }

    public function test_idempotent(): void
    {
        global $wpdb;
        $migrator = new Migrator($wpdb);
        $migrator->migrate();
        $migrator->migrate(); // doit pas planter
        self::assertSame(Plugin::DB_VERSION, (int) get_option('tb_db_version'));
    }
}
```

- [ ] **Step 4: Run → fail**

Run: `composer test:integration -- --filter MigratorTest`
Expected: `Migrator` n'existe pas.

- [ ] **Step 5: Implémenter `src/Persistence/Migrator.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Plugin;
use wpdb;

final class Migrator
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function migrate(): void
    {
        $currentVersion = (int) get_option('tb_db_version', 0);
        if ($currentVersion >= Plugin::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $this->wpdb->get_charset_collate();
        $prefix  = $this->wpdb->prefix;

        $statements = [
            "CREATE TABLE {$prefix}tb_services (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                duration_min SMALLINT UNSIGNED NOT NULL,
                buffer_before_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                buffer_after_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                min_lead_time_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
                max_horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                color VARCHAR(7) NOT NULL DEFAULT '#0ea5e9',
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                settings LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_slug (slug)
            ) {$charset};",

            "CREATE TABLE {$prefix}tb_bookings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_uid CHAR(36) NOT NULL,
                service_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL,
                starts_at_utc DATETIME NOT NULL,
                ends_at_utc DATETIME NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                customer_name VARCHAR(160) NOT NULL,
                customer_email VARCHAR(200) NOT NULL,
                customer_phone VARCHAR(40) NOT NULL,
                customer_address TEXT NULL,
                customer_meta LONGTEXT NULL,
                notes TEXT NULL,
                google_event_id VARCHAR(255) NULL,
                google_event_etag VARCHAR(255) NULL,
                decision_token_hash VARCHAR(64) NULL,
                reminder_sent_at DATETIME NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_public_uid (public_uid),
                KEY idx_status_starts (status, starts_at_utc),
                KEY idx_google_event (google_event_id)
            ) {$charset};",

            "CREATE TABLE {$prefix}tb_busy_blocks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(16) NOT NULL,
                source_id VARCHAR(255) NOT NULL,
                google_account_id BIGINT UNSIGNED NULL,
                starts_at_utc DATETIME NOT NULL,
                ends_at_utc DATETIME NOT NULL,
                summary VARCHAR(255) NULL,
                last_synced_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_source (source, source_id),
                KEY idx_range (starts_at_utc, ends_at_utc)
            ) {$charset};",

            "CREATE TABLE {$prefix}tb_google_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                label VARCHAR(120) NOT NULL,
                calendar_id VARCHAR(200) NOT NULL,
                oauth_refresh_token_enc LONGTEXT NULL,
                oauth_access_token_enc LONGTEXT NULL,
                oauth_expires_at DATETIME NULL,
                watch_channel_id VARCHAR(80) NULL,
                watch_resource_id VARCHAR(255) NULL,
                watch_token_secret VARCHAR(80) NULL,
                watch_expires_at DATETIME NULL,
                sync_token VARCHAR(255) NULL,
                last_full_sync_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) {$charset};",

            "CREATE TABLE {$prefix}tb_sync_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ts DATETIME NOT NULL,
                level VARCHAR(10) NOT NULL,
                direction VARCHAR(8) NOT NULL,
                entity VARCHAR(32) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                google_event_id VARCHAR(255) NULL,
                action VARCHAR(40) NOT NULL,
                payload LONGTEXT NULL,
                status VARCHAR(16) NOT NULL,
                error_message TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_ts (ts),
                KEY idx_entity (entity, entity_id)
            ) {$charset};",

            "CREATE TABLE {$prefix}tb_mail_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_key VARCHAR(64) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                html_body LONGTEXT NOT NULL,
                text_body LONGTEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                updated_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_event_key (event_key)
            ) {$charset};",
        ];

        foreach ($statements as $sql) {
            dbDelta($sql);
        }

        update_option('tb_db_version', Plugin::DB_VERSION, false);
    }
}
```

- [ ] **Step 6: Run → vert**

Run: `composer test:integration -- --filter MigratorTest`
Expected: 2 OK (skip si WP test suite absente).

- [ ] **Step 7: Commit**

```bash
git add src/Persistence/Migrator.php tests/Integration/bootstrap-wp.php tests/Integration/MigratorTest.php phpunit.xml composer.json
git commit -m "feat(persistence): add Migrator with 6 plugin tables (db v1)"
```

---

## Task 13 : Activator + Deactivator + branchement activation hook

**Files:**
- Create: `src/Activator.php`
- Create: `src/Deactivator.php`
- Modify: `src/Plugin.php` (méthode `register()`)
- Modify: `trinity-booking.php` (hooks)

L'activation : crée les tables, génère le secret HMAC, seed les services par défaut (PV + IRVE), seed les templates par défaut (lignes vides — vraies templates en Plan 2).

- [ ] **Step 1: Test de l'Activator (intégration)**

Create: `tests/Integration/ActivatorTest.php`

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Activator;

final class ActivatorTest extends WP_UnitTestCase
{
    public function test_activate_seeds_services_and_secret(): void
    {
        delete_option('tb_decision_secret');
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}tb_services");

        Activator::activate();

        $secret = get_option('tb_decision_secret');
        self::assertIsString($secret);
        self::assertSame(64, strlen($secret)); // 32 octets hex

        $slugs = $wpdb->get_col("SELECT slug FROM {$wpdb->prefix}tb_services ORDER BY sort_order");
        self::assertSame(['pv', 'irve'], $slugs);
    }

    public function test_activate_is_idempotent(): void
    {
        $first = get_option('tb_decision_secret');
        Activator::activate();
        $second = get_option('tb_decision_secret');
        self::assertSame($first, $second);

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tb_services");
        self::assertSame(2, $count);
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Écrire `src/Activator.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking;

use Trinity\Booking\Persistence\Migrator;

final class Activator
{
    public static function activate(): void
    {
        global $wpdb;
        (new Migrator($wpdb))->migrate();

        self::ensureDecisionSecret();
        self::seedServices($wpdb);
    }

    private static function ensureDecisionSecret(): void
    {
        $existing = get_option('tb_decision_secret');
        if (!is_string($existing) || strlen($existing) !== 64) {
            update_option('tb_decision_secret', bin2hex(random_bytes(32)), false);
        }
    }

    /**
     * @param \wpdb $wpdb
     */
    private static function seedServices(\wpdb $wpdb): void
    {
        $defaults = [
            [
                'slug' => 'pv',
                'name' => 'Photovoltaïque',
                'duration_min' => 90,
                'sort_order' => 1,
                'color' => '#f59e0b',
            ],
            [
                'slug' => 'irve',
                'name' => 'Borne de recharge',
                'duration_min' => 45,
                'sort_order' => 2,
                'color' => '#10b981',
            ],
        ];

        $weeklyHours = [
            1 => [['open' => '09:00', 'close' => '18:00']],
            2 => [['open' => '09:00', 'close' => '18:00']],
            3 => [['open' => '09:00', 'close' => '18:00']],
            4 => [['open' => '09:00', 'close' => '18:00']],
            5 => [['open' => '09:00', 'close' => '18:00']],
            6 => [['open' => '09:00', 'close' => '13:00']],
        ];

        $now = current_time('mysql', true);

        foreach ($defaults as $row) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$wpdb->prefix}tb_services WHERE slug = %s", $row['slug'])
            );
            if ($exists) {
                continue;
            }
            $wpdb->insert(
                "{$wpdb->prefix}tb_services",
                [
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'duration_min' => $row['duration_min'],
                    'buffer_before_min' => 0,
                    'buffer_after_min' => 30,
                    'min_lead_time_hours' => 24,
                    'max_horizon_days' => 60,
                    'color' => $row['color'],
                    'active' => 1,
                    'sort_order' => $row['sort_order'],
                    'settings' => json_encode(['weekly_hours' => $weeklyHours], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s','%s','%d','%d','%d','%d','%d','%s','%d','%d','%s','%s','%s']
            );
        }
    }
}
```

- [ ] **Step 4: Écrire `src/Deactivator.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Cron clear viendra dans Plan 2 (reminders).
        // Watch channel unsubscribe viendra dans Plan 4.
    }
}
```

- [ ] **Step 5: Brancher dans `trinity-booking.php`**

Modify le fichier `trinity-booking.php` — ajouter avant le `Plugin::boot(...)` :

```php
register_activation_hook(__FILE__, [\Trinity\Booking\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Trinity\Booking\Deactivator::class, 'deactivate']);

\Trinity\Booking\Plugin::boot(__FILE__);
```

- [ ] **Step 6: Run → vert**

Run: `composer test:integration -- --filter ActivatorTest`

- [ ] **Step 7: Commit**

```bash
git add src/Activator.php src/Deactivator.php trinity-booking.php tests/Integration/ActivatorTest.php
git commit -m "feat: add Activator (migrate + seed) and Deactivator hooks"
```

---

## Task 14 : Persistence — `ServiceRepository`

**Files:**
- Create: `src/Persistence/ServiceRepository.php`
- Create: `tests/Integration/ServiceRepositoryTest.php`

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Activator;
use Trinity\Booking\Persistence\ServiceRepository;

final class ServiceRepositoryTest extends WP_UnitTestCase
{
    private ServiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->repo = new ServiceRepository($wpdb);
    }

    public function test_find_by_slug(): void
    {
        $svc = $this->repo->findBySlug('pv');
        self::assertNotNull($svc);
        self::assertSame(90, $svc->durationMin);
    }

    public function test_find_active(): void
    {
        $active = $this->repo->findAllActive();
        self::assertCount(2, $active);
    }

    public function test_find_by_slug_returns_null_when_missing(): void
    {
        self::assertNull($this->repo->findBySlug('inexistant'));
    }

    public function test_find_by_id(): void
    {
        $svc = $this->repo->findBySlug('pv');
        self::assertNotNull($svc->id);
        $found = $this->repo->findById($svc->id);
        self::assertNotNull($found);
        self::assertSame('pv', $found->slug);
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\Service;
use wpdb;

final class ServiceRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_services';
    }

    public function findById(int $id): ?Service
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? Service::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?Service
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE slug = %s", $slug),
            ARRAY_A
        );
        return is_array($row) ? Service::fromRow($row) : null;
    }

    /**
     * @return list<Service>
     */
    public function findAllActive(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE active = 1 ORDER BY sort_order, id",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(static fn (array $row) => Service::fromRow($row), $rows));
    }
}
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Persistence/ServiceRepository.php tests/Integration/ServiceRepositoryTest.php
git commit -m "feat(persistence): add ServiceRepository read methods"
```

---

## Task 15 : Persistence — `BookingRepository`

**Files:**
- Create: `src/Persistence/BookingRepository.php`
- Create: `tests/Integration/BookingRepositoryTest.php`

Méthodes : `save(Booking)`, `findById`, `findByPublicUid`, `findOverlapping(serviceId, slot)`.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_UnitTestCase;
use Trinity\Booking\Activator;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\ServiceRepository;
use DateTimeImmutable;
use DateTimeZone;

final class BookingRepositoryTest extends WP_UnitTestCase
{
    private BookingRepository $bookings;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->bookings = new BookingRepository($wpdb);
        $services = new ServiceRepository($wpdb);
        $this->serviceId = $services->findBySlug('pv')->id;
    }

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private function pending(string $start, string $end, string $email = 'a@b.fr'): Booking
    {
        $slot = new TimeSlot($this->utc($start), $this->utc($end));
        return Booking::createPending(
            serviceId: $this->serviceId,
            slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Test', customerEmail: $email,
            customerPhone: '0600000000', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
    }

    public function test_save_assigns_id_and_reload(): void
    {
        $b = $this->pending('2026-06-01T08:00:00Z', '2026-06-01T09:30:00Z');
        $this->bookings->save($b);
        self::assertNotNull($b->id());

        $reloaded = $this->bookings->findById($b->id());
        self::assertNotNull($reloaded);
        self::assertSame('a@b.fr', $reloaded->customerEmail());
    }

    public function test_find_by_public_uid(): void
    {
        $b = $this->pending('2026-06-02T08:00:00Z', '2026-06-02T09:30:00Z');
        $this->bookings->save($b);
        $found = $this->bookings->findByPublicUid($b->publicUid());
        self::assertNotNull($found);
        self::assertSame($b->id(), $found->id());
    }

    public function test_find_overlapping_only_blocking_statuses(): void
    {
        $a = $this->pending('2026-06-03T08:00:00Z', '2026-06-03T09:30:00Z');
        $this->bookings->save($a);

        $overlapping = $this->bookings->findOverlapping(
            $this->serviceId,
            new TimeSlot($this->utc('2026-06-03T09:00:00Z'), $this->utc('2026-06-03T10:00:00Z'))
        );
        self::assertCount(1, $overlapping);

        $a->cancel();
        $this->bookings->save($a);

        $overlapping = $this->bookings->findOverlapping(
            $this->serviceId,
            new TimeSlot($this->utc('2026-06-03T09:00:00Z'), $this->utc('2026-06-03T10:00:00Z'))
        );
        self::assertCount(0, $overlapping);
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;
use ReflectionClass;

final class BookingRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_bookings';
    }

    public function save(Booking $booking): void
    {
        $row = $this->toRow($booking);
        if ($booking->id() === null) {
            $this->wpdb->insert($this->table, $row);
            $booking->assignId((int) $this->wpdb->insert_id);
            return;
        }
        $this->wpdb->update($this->table, $row, ['id' => $booking->id()]);
    }

    public function findById(int $id): ?Booking
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function findByPublicUid(string $uid): ?Booking
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE public_uid = %s", $uid),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    /**
     * @return list<Booking>
     */
    public function findOverlapping(int $serviceId, TimeSlot $slot): array
    {
        $blocking = [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value];
        $placeholders = implode(',', array_fill(0, count($blocking), '%s'));
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE service_id = %d
                AND status IN ({$placeholders})
                AND starts_at_utc < %s
                AND ends_at_utc > %s",
            $serviceId,
            ...$blocking,
            $slot->end->format('Y-m-d H:i:s'),
            $slot->start->format('Y-m-d H:i:s'),
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row) => $this->fromRow($row), $rows));
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Booking $b): array
    {
        return [
            'public_uid' => $b->publicUid(),
            'service_id' => $b->serviceId(),
            'status' => $b->status()->value,
            'starts_at_utc' => $b->slot()->start->format('Y-m-d H:i:s'),
            'ends_at_utc' => $b->slot()->end->format('Y-m-d H:i:s'),
            'timezone' => $b->timezone(),
            'customer_name' => $b->customerName(),
            'customer_email' => $b->customerEmail(),
            'customer_phone' => $b->customerPhone(),
            'customer_address' => $b->customerAddress(),
            'customer_meta' => json_encode($b->customerMeta(), JSON_UNESCAPED_UNICODE),
            'notes' => $b->notes(),
            'google_event_id' => $b->googleEventId(),
            'google_event_etag' => $b->googleEventEtag(),
            'decision_token_hash' => $b->decisionTokenHash(),
            'reminder_sent_at' => $b->reminderSentAt()?->format('Y-m-d H:i:s'),
            'created_at' => $b->createdAt()->format('Y-m-d H:i:s'),
            'updated_at' => $b->updatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Booking
    {
        $utc = new DateTimeZone('UTC');
        $slot = new TimeSlot(
            new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
            new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
        );

        // On reconstruit l'agrégat via reflection (constructeur privé)
        $ref = new ReflectionClass(Booking::class);
        $booking = $ref->newInstanceWithoutConstructor();

        $set = static function (string $prop, mixed $value) use ($ref, $booking): void {
            $p = $ref->getProperty($prop);
            $p->setValue($booking, $value);
        };

        $meta = is_string($row['customer_meta'] ?? null) ? (array) (json_decode((string) $row['customer_meta'], true) ?? []) : [];

        $set('id', (int) $row['id']);
        $set('publicUid', (string) $row['public_uid']);
        $set('serviceId', (int) $row['service_id']);
        $set('status', BookingStatus::from((string) $row['status']));
        $set('slot', $slot);
        $set('timezone', (string) $row['timezone']);
        $set('customerName', (string) $row['customer_name']);
        $set('customerEmail', (string) $row['customer_email']);
        $set('customerPhone', (string) $row['customer_phone']);
        $set('customerAddress', (string) ($row['customer_address'] ?? ''));
        $set('customerMeta', $meta);
        $set('notes', (string) ($row['notes'] ?? ''));
        $set('googleEventId', $row['google_event_id'] !== null ? (string) $row['google_event_id'] : null);
        $set('googleEventEtag', $row['google_event_etag'] !== null ? (string) $row['google_event_etag'] : null);
        $set('decisionTokenHash', $row['decision_token_hash'] !== null ? (string) $row['decision_token_hash'] : null);
        $set('reminderSentAt', $row['reminder_sent_at'] !== null ? new DateTimeImmutable((string) $row['reminder_sent_at'], $utc) : null);
        $set('createdAt', new DateTimeImmutable((string) $row['created_at'], $utc));
        $set('updatedAt', new DateTimeImmutable((string) $row['updated_at'], $utc));

        return $booking;
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test:integration -- --filter BookingRepositoryTest`

- [ ] **Step 5: Commit**

```bash
git add src/Persistence/BookingRepository.php tests/Integration/BookingRepositoryTest.php
git commit -m "feat(persistence): add BookingRepository with save/find/overlap"
```

---

## Task 16 : Persistence — `BusyBlockRepository` (lecture seule pour l'instant)

**Files:**
- Create: `src/Persistence/BusyBlockRepository.php`

Pas de test dédié — sera utilisé par `AvailabilityCalculator` (testé en Task 18). On expose `findInRange()`.

- [ ] **Step 1: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\BusyBlock;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final class BusyBlockRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_busy_blocks';
    }

    /**
     * @return list<BusyBlock>
     */
    public function findInRange(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
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
        if (!is_array($rows)) {
            return [];
        }
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = new BusyBlock(
                id: (int) $row['id'],
                source: (string) $row['source'],
                sourceId: (string) $row['source_id'],
                googleAccountId: $row['google_account_id'] !== null ? (int) $row['google_account_id'] : null,
                slot: new TimeSlot(
                    new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
                    new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
                ),
                summary: (string) ($row['summary'] ?? ''),
            );
        }
        return $out;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Persistence/BusyBlockRepository.php
git commit -m "feat(persistence): add BusyBlockRepository read"
```

---

## Task 17 : Availability — `SlotGenerator`

**Files:**
- Create: `src/Availability/SlotGenerator.php`
- Create: `tests/Unit/Availability/SlotGeneratorTest.php`

Génère, pour un `Service` et un intervalle de dates, la liste brute des créneaux candidats avant exclusion. Aligne sur le pas de 15 min, en respectant les horaires hebdomadaires du service, dans le fuseau WP, puis convertit en UTC.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Availability;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Availability\SlotGenerator;
use Trinity\Booking\Domain\Service;
use DateTimeImmutable;
use DateTimeZone;

final class SlotGeneratorTest extends TestCase
{
    private function service(int $duration = 90): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'PV',
            durationMin: $duration,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 30,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '12:00']],
            ],
            active: true, color: '#000',
        );
    }

    public function test_generates_aligned_slots_for_one_day(): void
    {
        $svc = $this->service(90);
        $gen = new SlotGenerator(stepMinutes: 15, siteTimezone: 'Europe/Paris');
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-01', new DateTimeZone('Europe/Paris')),
            to:   new DateTimeImmutable('2026-06-02', new DateTimeZone('Europe/Paris')),
        );

        // 1 juin 2026 = lundi. Horaires 9-12, durée 90 min, pas 15.
        // Slots possibles : 09:00, 09:15, 09:30, … jusqu'au dernier qui finit ≤ 12:00.
        // 09:00→10:30, 09:15→10:45, …, 10:30→12:00. = 7 slots.
        self::assertCount(7, $slots);
        self::assertSame('2026-06-01T07:00:00+00:00', $slots[0]->start->format('c')); // 09:00 Paris en juin = UTC+2
        self::assertSame('2026-06-01T08:30:00+00:00', $slots[0]->end->format('c'));
    }

    public function test_generates_nothing_for_closed_day(): void
    {
        $svc = $this->service(90);
        $gen = new SlotGenerator(stepMinutes: 15, siteTimezone: 'Europe/Paris');
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-07', new DateTimeZone('Europe/Paris')), // dimanche
            to:   new DateTimeImmutable('2026-06-08', new DateTimeZone('Europe/Paris')),
        );
        self::assertSame([], $slots);
    }

    public function test_min_lead_time_filters_too_close_slots(): void
    {
        $svc = new Service(
            id: 1, slug: 'pv', name: 'PV',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 48, maxHorizonDays: 30,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '12:00']],
            ],
            active: true, color: '#000',
        );
        $gen = new SlotGenerator(stepMinutes: 15, siteTimezone: 'Europe/Paris', now: new DateTimeImmutable('2026-05-31T10:00:00', new DateTimeZone('UTC')));
        $slots = $gen->generate(
            $svc,
            from: new DateTimeImmutable('2026-06-01', new DateTimeZone('Europe/Paris')),
            to:   new DateTimeImmutable('2026-06-02', new DateTimeZone('Europe/Paris')),
        );
        // 31 mai 10:00 UTC + 48h = 2 juin 10:00 UTC, donc tous les slots du 1er juin sont filtrés.
        self::assertSame([], $slots);
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Availability;

use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use DatePeriod;

final class SlotGenerator
{
    private DateTimeImmutable $now;

    public function __construct(
        public readonly int $stepMinutes,
        public readonly string $siteTimezone,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return list<TimeSlot> en UTC, triés croissants
     */
    public function generate(Service $service, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $tz = new DateTimeZone($this->siteTimezone);
        $utc = new DateTimeZone('UTC');
        $minStartUtc = $this->now->modify('+' . $service->minLeadTimeHours . ' hours');
        $maxStartUtc = $this->now->modify('+' . $service->maxHorizonDays . ' days');

        $fromLocal = $from->setTimezone($tz);
        $toLocal   = $to->setTimezone($tz);

        $oneDay = new DateInterval('P1D');
        $days = new DatePeriod(
            $fromLocal->setTime(0, 0),
            $oneDay,
            $toLocal->setTime(0, 0),
        );

        $slots = [];
        foreach ($days as $day) {
            $isoDay = (int) $day->format('N');
            foreach ($service->weeklyHoursForIsoDay($isoDay) as $window) {
                [$oh, $om] = array_map('intval', explode(':', $window['open']));
                [$ch, $cm] = array_map('intval', explode(':', $window['close']));
                $windowOpen  = $day->setTime($oh, $om);
                $windowClose = $day->setTime($ch, $cm);

                $cursor = $windowOpen;
                while (true) {
                    $startLocal = $cursor;
                    $endLocal   = $cursor->modify('+' . $service->durationMin . ' minutes');
                    if ($endLocal > $windowClose) {
                        break;
                    }
                    $startUtc = $startLocal->setTimezone($utc);
                    $endUtc   = $endLocal->setTimezone($utc);

                    if ($startUtc >= $minStartUtc && $startUtc <= $maxStartUtc) {
                        $slots[] = new TimeSlot($startUtc, $endUtc);
                    }
                    $cursor = $cursor->modify('+' . $this->stepMinutes . ' minutes');
                }
            }
        }

        usort($slots, static fn (TimeSlot $a, TimeSlot $b) => $a->start <=> $b->start);
        return $slots;
    }
}
```

- [ ] **Step 4: Run → vert**

Run: `composer test -- --filter SlotGeneratorTest`

- [ ] **Step 5: Commit**

```bash
git add src/Availability/SlotGenerator.php tests/Unit/Availability/SlotGeneratorTest.php
git commit -m "feat(availability): add SlotGenerator with weekly hours and lead time"
```

---

## Task 18 : Availability — `AvailabilityCalculator`

**Files:**
- Create: `src/Availability/AvailabilityCalculator.php`
- Create: `tests/Unit/Availability/AvailabilityCalculatorTest.php`

Reçoit les slots candidats + bookings bloquants + busy blocks, applique les buffers, retourne les slots libres.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Availability;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Availability\AvailabilityCalculator;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class AvailabilityCalculatorTest extends TestCase
{
    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function test_filters_slots_overlapping_busy_with_buffer(): void
    {
        $candidate1 = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $candidate2 = new TimeSlot($this->utc('2026-06-01T09:30:00Z'), $this->utc('2026-06-01T11:00:00Z'));
        $candidate3 = new TimeSlot($this->utc('2026-06-01T11:00:00Z'), $this->utc('2026-06-01T12:30:00Z'));

        $busy = [new TimeSlot($this->utc('2026-06-01T09:45:00Z'), $this->utc('2026-06-01T10:30:00Z'))];

        $calc = new AvailabilityCalculator(bufferBeforeMin: 30, bufferAfterMin: 30);
        $free = $calc->filter([$candidate1, $candidate2, $candidate3], $busy);

        // Candidate1 (08:00-09:30) avec buffer = 07:30-10:00 → chevauche le busy 09:45-10:30 → exclu
        // Candidate2 (09:30-11:00) avec buffer = 09:00-11:30 → chevauche → exclu
        // Candidate3 (11:00-12:30) avec buffer = 10:30-13:00 → chevauche jusque 10:30 (tangent → ok)
        self::assertCount(1, $free);
        self::assertSame('2026-06-01T11:00:00+00:00', $free[0]->start->format('c'));
    }

    public function test_no_busy_returns_all(): void
    {
        $candidate = new TimeSlot($this->utc('2026-06-01T08:00:00Z'), $this->utc('2026-06-01T09:30:00Z'));
        $calc = new AvailabilityCalculator(0, 0);
        self::assertSame([$candidate], $calc->filter([$candidate], []));
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Availability;

use Trinity\Booking\Domain\TimeSlot;

final class AvailabilityCalculator
{
    public function __construct(
        public readonly int $bufferBeforeMin,
        public readonly int $bufferAfterMin,
    ) {
    }

    /**
     * @param list<TimeSlot> $candidates
     * @param list<TimeSlot> $busy        plages déjà occupées (bookings actifs + busy blocks)
     * @return list<TimeSlot>
     */
    public function filter(array $candidates, array $busy): array
    {
        if ($busy === []) {
            return array_values($candidates);
        }
        $free = [];
        foreach ($candidates as $slot) {
            $expanded = $slot->expand($this->bufferBeforeMin, $this->bufferAfterMin);
            $blocked = false;
            foreach ($busy as $b) {
                if ($expanded->overlaps($b)) {
                    $blocked = true;
                    break;
                }
            }
            if (!$blocked) {
                $free[] = $slot;
            }
        }
        return $free;
    }
}
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Availability/AvailabilityCalculator.php tests/Unit/Availability/AvailabilityCalculatorTest.php
git commit -m "feat(availability): add AvailabilityCalculator with buffer-aware filtering"
```

---

## Task 19 : Booking — Exceptions du domaine

**Files:**
- Create: `src/Booking/Exceptions/SlotUnavailable.php`
- Create: `src/Booking/Exceptions/InvalidBookingInput.php`
- Create: `src/Booking/Exceptions/BookingNotFound.php`

- [ ] **Step 1: Écrire les 3 exceptions**

`src/Booking/Exceptions/SlotUnavailable.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking\Exceptions;

final class SlotUnavailable extends \DomainException
{
}
```

`src/Booking/Exceptions/InvalidBookingInput.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking\Exceptions;

final class InvalidBookingInput extends \DomainException
{
    /**
     * @param array<string, string> $errors  champ → message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid booking input.');
    }
}
```

`src/Booking/Exceptions/BookingNotFound.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking\Exceptions;

final class BookingNotFound extends \DomainException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Booking/Exceptions/
git commit -m "feat(booking): add domain exceptions"
```

---

## Task 20 : Booking — `DecisionTokenSigner`

**Files:**
- Create: `src/Booking/DecisionTokenSigner.php`
- Create: `tests/Unit/Booking/DecisionTokenSignerTest.php`

Génère/vérifie un token HMAC pour les URLs publiques (cancel client en Plan 1, decide admin en Plan 2).

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Booking\DecisionTokenSigner;

final class DecisionTokenSignerTest extends TestCase
{
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new DecisionTokenSigner('test-secret-32-bytes-min-length-ok');
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $exp = time() + 3600;
        $sig = $this->signer->sign('booking|42|confirm', $exp);
        self::assertTrue($this->signer->verify('booking|42|confirm', $exp, $sig));
    }

    public function test_verify_rejects_wrong_signature(): void
    {
        $exp = time() + 3600;
        self::assertFalse($this->signer->verify('booking|42|confirm', $exp, 'bogus'));
    }

    public function test_verify_rejects_expired(): void
    {
        $past = time() - 60;
        $sig = $this->signer->sign('booking|42|confirm', $past);
        self::assertFalse($this->signer->verify('booking|42|confirm', $past, $sig));
    }

    public function test_uses_constant_time_comparison(): void
    {
        // smoke test : aucune assertion mais on évite hash_equals → comparison
        $exp = time() + 60;
        $sig = $this->signer->sign('payload', $exp);
        self::assertTrue($this->signer->verify('payload', $exp, $sig));
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

final class DecisionTokenSigner
{
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Decision secret must be at least 16 characters.');
        }
    }

    public function sign(string $payload, int $expiresAtUnix): string
    {
        return hash_hmac('sha256', $payload . '|' . $expiresAtUnix, $this->secret);
    }

    public function verify(string $payload, int $expiresAtUnix, string $signature): bool
    {
        if ($expiresAtUnix < time()) {
            return false;
        }
        $expected = $this->sign($payload, $expiresAtUnix);
        return hash_equals($expected, $signature);
    }
}
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Booking/DecisionTokenSigner.php tests/Unit/Booking/DecisionTokenSignerTest.php
git commit -m "feat(booking): add HMAC token signer for decision URLs"
```

---

## Task 21 : Booking — Cas d'usage `CreateBooking`

**Files:**
- Create: `src/Booking/CreateBooking.php`
- Create: `tests/Unit/Booking/CreateBookingTest.php`

Le cas d'usage prend une commande déjà validée syntaxiquement (la sanitization HTTP arrive dans le controller), revérifie la dispo dans la fenêtre, refuse si chevauchement, sinon enregistre.

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Booking\CreateBooking;
use Trinity\Booking\Booking\Exceptions\InvalidBookingInput;
use Trinity\Booking\Booking\Exceptions\SlotUnavailable;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Domain\Booking;
use DateTimeImmutable;
use DateTimeZone;

final class CreateBookingTest extends TestCase
{
    private function service(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'PV',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 60,
            weeklyHours: [1 => [['open' => '09:00', 'close' => '18:00']]],
            active: true, color: '#000',
        );
    }

    private function slot(): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
    }

    public function test_happy_path_saves_pending_booking(): void
    {
        $saved = null;
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: function (Booking $b) use (&$saved): void { $saved = $b; $b->assignId(123); },
        );
        $created = $useCase->execute([
            'service' => $this->service(),
            'slot' => $this->slot(),
            'timezone' => 'Europe/Paris',
            'customer_name' => 'Jean Test',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'customer_meta' => [],
            'notes' => '',
            'consent' => true,
        ]);
        self::assertSame(123, $created->id());
        self::assertNotNull($saved);
    }

    public function test_rejects_when_slot_unavailable(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => false,
            persist: fn (Booking $b) => null,
        );
        $this->expectException(SlotUnavailable::class);
        $useCase->execute([
            'service' => $this->service(),
            'slot' => $this->slot(),
            'timezone' => 'Europe/Paris',
            'customer_name' => 'Jean',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'customer_meta' => [],
            'notes' => '',
            'consent' => true,
        ]);
    }

    public function test_rejects_when_consent_missing(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: fn (Booking $b) => null,
        );
        try {
            $useCase->execute([
                'service' => $this->service(),
                'slot' => $this->slot(),
                'timezone' => 'Europe/Paris',
                'customer_name' => 'Jean',
                'customer_email' => 'jean@test.fr',
                'customer_phone' => '0600000000',
                'customer_address' => '1 rue X',
                'customer_meta' => [],
                'notes' => '',
                'consent' => false,
            ]);
            self::fail('Expected InvalidBookingInput');
        } catch (InvalidBookingInput $e) {
            self::assertArrayHasKey('consent', $e->errors);
        }
    }

    public function test_rejects_invalid_email(): void
    {
        $useCase = new CreateBooking(
            slotIsFree: fn () => true,
            persist: fn (Booking $b) => null,
        );
        try {
            $useCase->execute([
                'service' => $this->service(),
                'slot' => $this->slot(),
                'timezone' => 'Europe/Paris',
                'customer_name' => 'Jean',
                'customer_email' => 'not-an-email',
                'customer_phone' => '0600000000',
                'customer_address' => '1 rue X',
                'customer_meta' => [],
                'notes' => '',
                'consent' => true,
            ]);
            self::fail('Expected InvalidBookingInput');
        } catch (InvalidBookingInput $e) {
            self::assertArrayHasKey('customer_email', $e->errors);
        }
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

use Trinity\Booking\Booking\Exceptions\InvalidBookingInput;
use Trinity\Booking\Booking\Exceptions\SlotUnavailable;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Closure;

/**
 * @phpstan-type Command array{
 *   service: Service,
 *   slot: TimeSlot,
 *   timezone: string,
 *   customer_name: string,
 *   customer_email: string,
 *   customer_phone: string,
 *   customer_address: string,
 *   customer_meta: array<string, mixed>,
 *   notes: string,
 *   consent: bool,
 * }
 */
final class CreateBooking
{
    /**
     * @param Closure(Service, TimeSlot): bool $slotIsFree
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $slotIsFree,
        private readonly Closure $persist,
    ) {
    }

    /**
     * @param Command $cmd
     */
    public function execute(array $cmd): Booking
    {
        $this->validate($cmd);

        if (!($this->slotIsFree)($cmd['service'], $cmd['slot'])) {
            throw new SlotUnavailable('Slot is no longer available.');
        }

        $booking = Booking::createPending(
            serviceId: $cmd['service']->id ?? throw new \LogicException('Service must have an id.'),
            slot: $cmd['slot'],
            timezone: $cmd['timezone'],
            customerName: $cmd['customer_name'],
            customerEmail: $cmd['customer_email'],
            customerPhone: $cmd['customer_phone'],
            customerAddress: $cmd['customer_address'],
            customerMeta: $cmd['customer_meta'],
            notes: $cmd['notes'],
        );

        ($this->persist)($booking);

        return $booking;
    }

    /**
     * @param Command $cmd
     */
    private function validate(array $cmd): void
    {
        $errors = [];
        if (!$cmd['consent']) {
            $errors['consent'] = 'Consent is required.';
        }
        if (trim($cmd['customer_name']) === '') {
            $errors['customer_name'] = 'Name is required.';
        }
        if (!filter_var($cmd['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['customer_email'] = 'Valid email required.';
        }
        if (preg_replace('/\D+/', '', $cmd['customer_phone']) === '' ) {
            $errors['customer_phone'] = 'Phone required.';
        }
        if ($errors !== []) {
            throw new InvalidBookingInput($errors);
        }
    }
}
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Booking/CreateBooking.php tests/Unit/Booking/CreateBookingTest.php
git commit -m "feat(booking): add CreateBooking use case with validation"
```

---

## Task 22 : Booking — Cas d'usage `CancelBooking`

**Files:**
- Create: `src/Booking/CancelBooking.php`
- Create: `tests/Unit/Booking/CancelBookingTest.php`

L'annulation par le client passe par le lien HMAC dans l'e-mail. En Plan 1 on n'envoie pas encore d'e-mail, mais le cas d'usage + endpoint existent (utiles pour tests et pour Plan 2).

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Booking\CancelBooking;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;

final class CancelBookingTest extends TestCase
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

    public function test_cancels_pending_booking(): void
    {
        $b = $this->pending();
        $b->assignId(7);
        $saved = false;
        $useCase = new CancelBooking(
            find: fn (string $uid) => $uid === $b->publicUid() ? $b : null,
            persist: function (Booking $bb) use (&$saved): void { $saved = true; },
        );
        $useCase->execute($b->publicUid());
        self::assertTrue($saved);
        self::assertSame(BookingStatus::CANCELLED, $b->status());
    }

    public function test_throws_when_booking_missing(): void
    {
        $useCase = new CancelBooking(
            find: fn (string $uid) => null,
            persist: fn (Booking $b) => null,
        );
        $this->expectException(BookingNotFound::class);
        $useCase->execute('missing-uid');
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Booking;

use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Domain\Booking;
use Closure;

final class CancelBooking
{
    /**
     * @param Closure(string): ?Booking $find
     * @param Closure(Booking): void $persist
     */
    public function __construct(
        private readonly Closure $find,
        private readonly Closure $persist,
    ) {
    }

    public function execute(string $publicUid): Booking
    {
        $booking = ($this->find)($publicUid);
        if ($booking === null) {
            throw new BookingNotFound('Booking not found.');
        }
        $booking->cancel();
        ($this->persist)($booking);
        return $booking;
    }
}
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Booking/CancelBooking.php tests/Unit/Booking/CancelBookingTest.php
git commit -m "feat(booking): add CancelBooking use case"
```

---

## Task 23 : HTTP — `RestRouter` (skeleton)

**Files:**
- Create: `src/Http/RestRouter.php`
- Modify: `src/Plugin.php` pour le brancher sur `rest_api_init`.

Le routeur enregistre tous les endpoints REST. Implémenté incrémentalement.

- [ ] **Step 1: Écrire `src/Http/RestRouter.php`** (vide pour l'instant)

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Plugin;

final class RestRouter
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // Les controllers vont s'enregistrer ici en Task 24+.
    }
}
```

- [ ] **Step 2: Mettre à jour `Plugin::register()`**

Replace dans `src/Plugin.php` la méthode `register()` (qui est vide) :

```php
private function register(): void
{
    $router = new Http\RestRouter();
    $router->register();
    $this->set(Http\RestRouter::class, $router);
}
```

- [ ] **Step 3: Smoke test PHPStan**

Run: `composer stan`
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/Http/RestRouter.php src/Plugin.php
git commit -m "feat(http): add RestRouter skeleton wired to rest_api_init"
```

---

## Task 24 : HTTP — `PublicBookingController` GET /services

**Files:**
- Create: `src/Http/PublicBookingController.php`
- Modify: `src/Http/RestRouter.php` pour brancher
- Create: `tests/Integration/PublicBookingControllerTest.php`

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use Trinity\Booking\Activator;

final class PublicBookingControllerTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
    }

    public function test_get_services_returns_active_list(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/services');
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        $slugs = array_column($data, 'slug');
        self::assertSame(['pv', 'irve'], $slugs);
    }
}
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Écrire `src/Http/PublicBookingController.php`**

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Persistence\ServiceRepository;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicBookingController
{
    public function __construct(private readonly ServiceRepository $services)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/services',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'listServices'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function listServices(WP_REST_Request $request): WP_REST_Response
    {
        $services = $this->services->findAllActive();
        $data = array_map(static fn ($s) => [
            'id'              => $s->id,
            'slug'            => $s->slug,
            'name'            => $s->name,
            'duration_min'    => $s->durationMin,
            'color'           => $s->color,
            'weekly_hours'    => $s->weeklyHours,
            'min_lead_hours'  => $s->minLeadTimeHours,
            'max_horizon_days'=> $s->maxHorizonDays,
        ], $services);

        return new WP_REST_Response($data, 200);
    }
}
```

- [ ] **Step 4: Brancher dans `RestRouter`**

Replace `src/Http/RestRouter.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Persistence\ServiceRepository;

final class RestRouter
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        global $wpdb;
        $services = new ServiceRepository($wpdb);

        (new PublicBookingController($services))->registerRoutes();
    }
}
```

- [ ] **Step 5: Run → vert**

Run: `composer test:integration -- --filter PublicBookingControllerTest`

- [ ] **Step 6: Commit**

```bash
git add src/Http/PublicBookingController.php src/Http/RestRouter.php tests/Integration/PublicBookingControllerTest.php
git commit -m "feat(http): expose GET /services public endpoint"
```

---

## Task 25 : HTTP — GET /availability

**Files:**
- Modify: `src/Http/PublicBookingController.php`
- Modify: `tests/Integration/PublicBookingControllerTest.php`

- [ ] **Step 1: Ajouter le test**

Append au fichier `PublicBookingControllerTest.php` :

```php
    public function test_get_availability_returns_slots(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'pv',
            'from'    => '2026-06-01',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertArrayHasKey('slots', $data);
        self::assertNotEmpty($data['slots']);
        self::assertArrayHasKey('start', $data['slots'][0]);
    }

    public function test_get_availability_unknown_service_returns_404(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'inconnu',
            'from'    => '2026-06-01',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(404, $response->get_status());
    }

    public function test_get_availability_invalid_date_returns_400(): void
    {
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $request->set_query_params([
            'service' => 'pv',
            'from'    => 'oops',
            'to'      => '2026-06-02',
        ]);
        $response = rest_do_request($request);
        self::assertSame(400, $response->get_status());
    }
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Étendre `PublicBookingController`**

Add à `src/Http/PublicBookingController.php` :

```php
use Trinity\Booking\Availability\AvailabilityCalculator;
use Trinity\Booking\Availability\SlotGenerator;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\BusyBlockRepository;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
```

Modifier le constructeur :

```php
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly BusyBlockRepository $busyBlocks,
        private readonly SlotGenerator $slotGenerator,
    ) {
    }
```

Ajouter dans `registerRoutes()` :

```php
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/availability',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getAvailability'],
                'permission_callback' => '__return_true',
                'args' => [
                    'service' => ['type' => 'string', 'required' => true],
                    'from'    => ['type' => 'string', 'required' => true],
                    'to'      => ['type' => 'string', 'required' => true],
                ],
            ]
        );
```

Ajouter la méthode :

```php
    public function getAvailability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $svc = $this->services->findBySlug((string) $request['service']);
        if ($svc === null) {
            return new WP_Error('tb_service_not_found', 'Service introuvable', ['status' => 404]);
        }

        try {
            $tz   = new DateTimeZone(wp_timezone_string());
            $from = new DateTimeImmutable((string) $request['from'], $tz);
            $to   = new DateTimeImmutable((string) $request['to'], $tz);
        } catch (\Exception $e) {
            return new WP_Error('tb_invalid_date', 'Date invalide', ['status' => 400]);
        }
        if ($from >= $to) {
            return new WP_Error('tb_invalid_date', 'from doit précéder to', ['status' => 400]);
        }

        $candidates = $this->slotGenerator->generate($svc, $from, $to);
        if ($candidates === []) {
            return new WP_REST_Response(['slots' => []], 200);
        }

        $rangeStart = $candidates[0]->start;
        $rangeEnd   = end($candidates)->end;

        // Bookings actifs sur l'intervalle
        $blocking = array_map(
            static fn ($b) => $b->slot(),
            $this->bookings->findOverlapping(
                $svc->id,
                new TimeSlot($rangeStart, $rangeEnd),
            ),
        );

        // Busy blocks (vide en Plan 1, structure prête)
        $busyEntries = $this->busyBlocks->findInRange($rangeStart, $rangeEnd);
        $busy = array_merge(
            $blocking,
            array_map(static fn ($bb) => $bb->slot, $busyEntries),
        );

        $calc = new AvailabilityCalculator(
            bufferBeforeMin: $svc->bufferBeforeMin,
            bufferAfterMin: $svc->bufferAfterMin,
        );
        $free = $calc->filter($candidates, $busy);

        $data = array_map(static fn ($s) => $s->toArray(), $free);
        return new WP_REST_Response(['slots' => $data], 200);
    }
```

- [ ] **Step 4: Adapter `RestRouter`**

Replace dans `registerRoutes()` :

```php
    public function registerRoutes(): void
    {
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $busy     = new BusyBlockRepository($wpdb);
        $generator = new SlotGenerator(
            stepMinutes: 15,
            siteTimezone: wp_timezone_string(),
        );

        (new PublicBookingController($services, $bookings, $busy, $generator))->registerRoutes();
    }
```

(et ajouter les `use` correspondants en haut)

- [ ] **Step 5: Run → vert**

Run: `composer test:integration -- --filter PublicBookingControllerTest`

- [ ] **Step 6: Commit**

```bash
git add src/Http/PublicBookingController.php src/Http/RestRouter.php tests/Integration/PublicBookingControllerTest.php
git commit -m "feat(http): expose GET /availability with buffer-aware filtering"
```

---

## Task 26 : HTTP — POST /bookings (création)

**Files:**
- Modify: `src/Http/PublicBookingController.php`
- Modify: `src/Http/RestRouter.php`
- Modify: `tests/Integration/PublicBookingControllerTest.php`

- [ ] **Step 1: Tests**

Append :

```php
    public function test_post_booking_happy_path(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Jean Test',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X, Paris',
            'notes' => '',
            'consent' => true,
            'website' => '', // honeypot
        ]));
        $response = rest_do_request($request);
        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('public_uid', $data);
    }

    public function test_post_booking_rejects_missing_consent(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Jean',
            'customer_email' => 'jean@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue X',
            'consent' => false,
        ]));
        $response = rest_do_request($request);
        self::assertSame(422, $response->get_status());
    }

    public function test_post_booking_honeypot_returns_201_silently(): void
    {
        $request = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $request->set_header('content-type', 'application/json');
        $request->set_body(json_encode([
            'service' => 'pv',
            'start'   => '2026-06-01T07:00:00+00:00',
            'customer_name' => 'Bot',
            'customer_email' => 'bot@spam.com',
            'customer_phone' => '0600',
            'customer_address' => 'x',
            'consent' => true,
            'website' => 'http://spam.tld',
        ]));
        $response = rest_do_request($request);
        // On retourne 201 fake pour ne pas signaler au bot que c'est filtré
        self::assertSame(201, $response->get_status());
        $data = $response->get_data();
        self::assertSame('honeypot', $data['public_uid']);
    }

    public function test_post_booking_double_booking_returns_409(): void
    {
        // 1er appel
        $body = [
            'service' => 'pv',
            'start'   => '2026-06-02T07:00:00+00:00',
            'customer_name' => 'A',
            'customer_email' => 'a@a.fr',
            'customer_phone' => '0600',
            'customer_address' => 'x',
            'consent' => true,
        ];
        $r1 = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r1->set_header('content-type', 'application/json');
        $r1->set_body(json_encode($body));
        $resp1 = rest_do_request($r1);
        self::assertSame(201, $resp1->get_status());

        // 2ème sur le même créneau
        $r2 = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r2->set_header('content-type', 'application/json');
        $r2->set_body(json_encode($body));
        $resp2 = rest_do_request($r2);
        self::assertSame(409, $resp2->get_status());
    }
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation — étendre le controller**

Add à `src/Http/PublicBookingController.php` (constructor : injecter `CreateBooking`) — version finale du fichier ci-dessous :

```php
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly BusyBlockRepository $busyBlocks,
        private readonly SlotGenerator $slotGenerator,
        private readonly \Trinity\Booking\Booking\CreateBooking $createBooking,
    ) {
    }
```

Ajouter à `registerRoutes()` :

```php
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/bookings',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createBooking'],
                'permission_callback' => '__return_true',
            ]
        );
```

Ajouter la méthode :

```php
    public function createBooking(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: [];

        // Honeypot : si rempli → succès factice
        if (!empty($params['website'])) {
            return new WP_REST_Response(['public_uid' => 'honeypot'], 201);
        }

        $svc = $this->services->findBySlug((string) ($params['service'] ?? ''));
        if ($svc === null) {
            return new WP_Error('tb_service_not_found', 'Service introuvable', ['status' => 404]);
        }

        try {
            $start = new DateTimeImmutable((string) ($params['start'] ?? ''), new DateTimeZone('UTC'));
            $start = $start->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return new WP_Error('tb_invalid_date', 'start invalide', ['status' => 400]);
        }
        $end = $start->modify('+' . $svc->durationMin . ' minutes');
        $slot = new TimeSlot($start, $end);

        $cmd = [
            'service'          => $svc,
            'slot'             => $slot,
            'timezone'         => wp_timezone_string(),
            'customer_name'    => sanitize_text_field((string) ($params['customer_name'] ?? '')),
            'customer_email'   => sanitize_email((string) ($params['customer_email'] ?? '')),
            'customer_phone'   => sanitize_text_field((string) ($params['customer_phone'] ?? '')),
            'customer_address' => sanitize_textarea_field((string) ($params['customer_address'] ?? '')),
            'customer_meta'    => is_array($params['customer_meta'] ?? null) ? $params['customer_meta'] : [],
            'notes'            => sanitize_textarea_field((string) ($params['notes'] ?? '')),
            'consent'          => (bool) ($params['consent'] ?? false),
        ];

        try {
            $booking = $this->createBooking->execute($cmd);
        } catch (\Trinity\Booking\Booking\Exceptions\InvalidBookingInput $e) {
            return new WP_Error('tb_invalid_input', 'Champs invalides', ['status' => 422, 'errors' => $e->errors]);
        } catch (\Trinity\Booking\Booking\Exceptions\SlotUnavailable $e) {
            return new WP_Error('tb_slot_unavailable', 'Créneau indisponible', ['status' => 409]);
        }

        return new WP_REST_Response([
            'public_uid' => $booking->publicUid(),
            'status'     => $booking->status()->value,
        ], 201);
    }
```

- [ ] **Step 4: Mettre à jour `RestRouter` pour injecter `CreateBooking`**

Dans `src/Http/RestRouter.php` `registerRoutes()` :

```php
        $createBooking = new \Trinity\Booking\Booking\CreateBooking(
            slotIsFree: function (\Trinity\Booking\Domain\Service $svc, \Trinity\Booking\Domain\TimeSlot $slot) use ($bookings, $busy): bool {
                $blocking = $bookings->findOverlapping($svc->id, $slot);
                if ($blocking !== []) return false;
                foreach ($busy->findInRange($slot->start, $slot->end) as $bb) {
                    if ($slot->overlaps($bb->slot)) return false;
                }
                return true;
            },
            persist: function (\Trinity\Booking\Domain\Booking $b) use ($bookings): void {
                $bookings->save($b);
            },
        );

        (new PublicBookingController($services, $bookings, $busy, $generator, $createBooking))->registerRoutes();
```

- [ ] **Step 5: Run → vert**

Run: `composer test:integration -- --filter PublicBookingControllerTest`

- [ ] **Step 6: Commit**

```bash
git add src/Http/PublicBookingController.php src/Http/RestRouter.php tests/Integration/PublicBookingControllerTest.php
git commit -m "feat(http): expose POST /bookings with validation and honeypot"
```

---

## Task 27 : HTTP — `PublicCancelController` GET /cancel

**Files:**
- Create: `src/Http/PublicCancelController.php`
- Modify: `src/Http/RestRouter.php`
- Modify: `tests/Integration/PublicBookingControllerTest.php` (ajouter cas)

L'URL signée a la forme `/wp-json/trinity-booking/v1/cancel?uid={uid}&exp={ts}&sig={hmac}`. Payload signé : `"cancel|{uid}"`.

- [ ] **Step 1: Test**

Ajouter au fichier de test (helper qui forge un token) :

```php
    private function signCancel(string $uid, int $exp): string
    {
        $secret = get_option('tb_decision_secret');
        return hash_hmac('sha256', 'cancel|' . $uid . '|' . $exp, $secret);
    }

    public function test_cancel_with_valid_token_returns_200(): void
    {
        // Crée un booking
        $r = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r->set_header('content-type', 'application/json');
        $r->set_body(json_encode([
            'service' => 'pv', 'start' => '2026-06-10T07:00:00+00:00',
            'customer_name' => 'A', 'customer_email' => 'a@a.fr',
            'customer_phone' => '0600', 'customer_address' => 'x', 'consent' => true,
        ]));
        $resp = rest_do_request($r);
        $uid = $resp->get_data()['public_uid'];

        $exp = time() + 3600;
        $sig = $this->signCancel($uid, $exp);
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/cancel');
        $req->set_query_params(['uid' => $uid, 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($req);
        self::assertSame(200, $response->get_status());
    }

    public function test_cancel_with_bad_signature_returns_403(): void
    {
        $exp = time() + 3600;
        $req = new WP_REST_Request('GET', '/trinity-booking/v1/cancel');
        $req->set_query_params(['uid' => 'fake', 'exp' => $exp, 'sig' => 'wrong']);
        $response = rest_do_request($req);
        self::assertSame(403, $response->get_status());
    }
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

`src/Http/PublicCancelController.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Booking\CancelBooking;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicCancelController
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly CancelBooking $cancel,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/cancel',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'uid' => ['type' => 'string', 'required' => true],
                    'exp' => ['type' => 'integer', 'required' => true],
                    'sig' => ['type' => 'string', 'required' => true],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $uid = (string) $request['uid'];
        $exp = (int) $request['exp'];
        $sig = (string) $request['sig'];
        $payload = 'cancel|' . $uid;

        if (!$this->signer->verify($payload, $exp, $sig)) {
            return new WP_Error('tb_invalid_token', 'Lien invalide ou expiré.', ['status' => 403]);
        }

        try {
            $this->cancel->execute($uid);
        } catch (BookingNotFound $e) {
            return new WP_Error('tb_not_found', 'Réservation introuvable.', ['status' => 404]);
        }

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }
}
```

- [ ] **Step 4: Brancher dans `RestRouter`**

Ajouter dans `registerRoutes()` :

```php
        $signer = new \Trinity\Booking\Booking\DecisionTokenSigner((string) get_option('tb_decision_secret'));
        $cancel = new \Trinity\Booking\Booking\CancelBooking(
            find: fn (string $uid) => $bookings->findByPublicUid($uid),
            persist: fn (\Trinity\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new PublicCancelController($signer, $cancel))->registerRoutes();
```

- [ ] **Step 5: Run → vert**

Run: `composer test:integration -- --filter PublicBookingControllerTest`

- [ ] **Step 6: Commit**

```bash
git add src/Http/PublicCancelController.php src/Http/RestRouter.php tests/Integration/PublicBookingControllerTest.php
git commit -m "feat(http): expose GET /cancel with HMAC verification"
```

---

## Task 28 : PublicFront — `Shortcode` `[trinity_booking]`

**Files:**
- Create: `src/PublicFront/Shortcode.php`
- Modify: `src/Plugin.php` pour enregistrer

- [ ] **Step 1: Implémentation (rendu minimal — JS chargera la suite)**

`src/PublicFront/Shortcode.php` :

```php
<?php
declare(strict_types=1);

namespace Trinity\Booking\PublicFront;

use Trinity\Booking\Persistence\ServiceRepository;
use Trinity\Booking\Plugin;

final class Shortcode
{
    public function __construct(private readonly ServiceRepository $services)
    {
    }

    public function register(): void
    {
        add_shortcode('trinity_booking', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueue']);
    }

    /**
     * @param array<string, string>|string $attrs
     */
    public function render($attrs): string
    {
        $attrs = is_array($attrs) ? $attrs : [];
        $service = isset($attrs['service']) ? sanitize_text_field((string) $attrs['service']) : 'pv';
        $svc = $this->services->findBySlug($service);
        if ($svc === null || !$svc->isActive()) {
            return '<div class="tb-error">' . esc_html__('Service inconnu', 'trinity-booking') . '</div>';
        }

        $this->markEnqueueNeeded();

        return sprintf(
            '<div class="tb-widget" data-tb-service="%s" data-tb-rest="%s"></div>',
            esc_attr($svc->slug),
            esc_url_raw(rest_url(Plugin::REST_NAMESPACE . '/')),
        );
    }

    public function maybeEnqueue(): void
    {
        if (!$this->shouldEnqueue()) {
            return;
        }
        $pluginUrl = plugin_dir_url(Plugin::instance()->pluginFile());
        wp_enqueue_style(
            'trinity-booking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.css',
            [],
            Plugin::VERSION
        );
        wp_enqueue_script(
            'trinity-booking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.js',
            [],
            Plugin::VERSION,
            true
        );
        wp_localize_script('trinity-booking-public', 'TrinityBooking', [
            'nonce'  => wp_create_nonce('wp_rest'),
            'locale' => get_locale(),
        ]);
    }

    private function markEnqueueNeeded(): void
    {
        if (!isset($GLOBALS['tb_enqueue_needed'])) {
            $GLOBALS['tb_enqueue_needed'] = true;
        }
    }

    private function shouldEnqueue(): bool
    {
        return !empty($GLOBALS['tb_enqueue_needed']);
    }
}
```

- [ ] **Step 2: Brancher dans `Plugin::register()`**

Dans `src/Plugin.php`, après `$router`, ajouter :

```php
    $services = new Persistence\ServiceRepository($GLOBALS['wpdb']);
    $shortcode = new PublicFront\Shortcode($services);
    $shortcode->register();
```

(le shortcode résout `$wpdb` lazy ; on l'instancie à l'init parce que `Plugin::boot` est appelé tôt, mais `$wpdb` existe déjà).

- [ ] **Step 3: Test fumée — vérifier qu'on ne casse pas PHPStan**

Run: `composer stan`

- [ ] **Step 4: Commit**

```bash
git add src/PublicFront/Shortcode.php src/Plugin.php
git commit -m "feat(public-front): register [trinity_booking] shortcode"
```

---

## Task 29 : PublicFront — Assets JS/CSS minimaux

**Files:**
- Create: `src/PublicFront/assets/booking.js`
- Create: `src/PublicFront/assets/booking.css`

Widget vanilla ~150 lignes : 3 étapes (date → créneau → infos), fetch sur les endpoints REST. Pas de FullCalendar pour la V1 minimale (simple calendrier HTML <input type="date">).

- [ ] **Step 1: Écrire `src/PublicFront/assets/booking.css`**

```css
.tb-widget { max-width: 560px; margin: 0 auto; font-family: system-ui, -apple-system, sans-serif; color: #111; }
.tb-step { padding: 16px 0; }
.tb-step h3 { margin: 0 0 8px; font-size: 18px; }
.tb-slot-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
.tb-slot { padding: 10px; border: 1px solid #d4d4d4; background: #fff; border-radius: 6px; cursor: pointer; font-size: 14px; text-align: center; }
.tb-slot:hover { border-color: #0ea5e9; }
.tb-slot.is-selected { background: #0ea5e9; color: #fff; border-color: #0284c7; }
.tb-field { display: block; margin: 10px 0; }
.tb-field label { display: block; font-size: 13px; margin-bottom: 4px; }
.tb-field input, .tb-field textarea { width: 100%; padding: 10px; border: 1px solid #d4d4d4; border-radius: 6px; font: inherit; box-sizing: border-box; }
.tb-error { color: #b91c1c; margin: 8px 0; }
.tb-success { color: #047857; margin: 8px 0; }
.tb-honeypot { position: absolute; left: -9999px; }
.tb-button { padding: 12px 18px; border: 0; background: #0ea5e9; color: #fff; border-radius: 6px; font: inherit; cursor: pointer; }
.tb-button:disabled { opacity: .5; cursor: not-allowed; }
.tb-loading { opacity: .6; pointer-events: none; }
```

- [ ] **Step 2: Écrire `src/PublicFront/assets/booking.js`**

```javascript
(function () {
  'use strict';

  function init(root) {
    var service = root.dataset.tbService;
    var rest = root.dataset.tbRest;
    var nonce = window.TrinityBooking && window.TrinityBooking.nonce;
    var locale = (window.TrinityBooking && window.TrinityBooking.locale) || 'fr-FR';

    var state = { date: null, start: null };

    render();

    function render() {
      root.innerHTML = '';
      root.append(
        stepDate(),
        stepSlots(),
        stepForm(),
        feedback()
      );
    }

    function stepDate() {
      var s = el('div', 'tb-step');
      s.append(h3('1. Choisissez une date'));
      var input = el('input');
      input.type = 'date';
      input.min = todayISO();
      input.addEventListener('change', function () {
        state.date = input.value;
        state.start = null;
        loadSlots();
      });
      s.append(input);
      return s;
    }

    function stepSlots() {
      var s = el('div', 'tb-step');
      s.id = 'tb-slots';
      s.append(h3('2. Choisissez un créneau'));
      var list = el('div', 'tb-slot-list');
      list.id = 'tb-slot-list';
      s.append(list);
      return s;
    }

    function stepForm() {
      var s = el('div', 'tb-step');
      s.id = 'tb-form';
      s.style.display = 'none';
      s.append(h3('3. Vos informations'));
      ['customer_name', 'customer_email', 'customer_phone', 'customer_address'].forEach(function (name) {
        s.append(field(name, labelFor(name), name === 'customer_address' ? 'textarea' : (name === 'customer_email' ? 'email' : 'text')));
      });
      // honeypot
      var hp = field('website', 'Website', 'text');
      hp.classList.add('tb-honeypot');
      hp.setAttribute('aria-hidden', 'true');
      s.append(hp);

      var consentWrap = el('label', 'tb-field');
      var consent = el('input');
      consent.type = 'checkbox';
      consent.name = 'consent';
      consent.required = true;
      consentWrap.append(consent, ' ', document.createTextNode('J’accepte que mes données soient utilisées pour me recontacter.'));
      s.append(consentWrap);

      var btn = el('button', 'tb-button');
      btn.type = 'button';
      btn.textContent = 'Réserver';
      btn.addEventListener('click', submit);
      s.append(btn);

      return s;
    }

    function feedback() {
      var f = el('div');
      f.id = 'tb-feedback';
      return f;
    }

    function loadSlots() {
      var list = root.querySelector('#tb-slot-list');
      list.textContent = '';
      var from = state.date;
      var to = addDays(from, 1);
      root.classList.add('tb-loading');
      fetch(rest + 'availability?service=' + encodeURIComponent(service) + '&from=' + from + '&to=' + to, {
        headers: { 'X-WP-Nonce': nonce || '' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          root.classList.remove('tb-loading');
          if (!data.slots || data.slots.length === 0) {
            list.append(text('Aucun créneau disponible ce jour-là.'));
            return;
          }
          data.slots.forEach(function (slot) {
            var b = el('button', 'tb-slot');
            b.type = 'button';
            b.textContent = formatTime(slot.start);
            b.dataset.start = slot.start;
            b.addEventListener('click', function () {
              Array.from(list.children).forEach(function (c) { c.classList.remove('is-selected'); });
              b.classList.add('is-selected');
              state.start = slot.start;
              root.querySelector('#tb-form').style.display = '';
            });
            list.append(b);
          });
        })
        .catch(function () {
          root.classList.remove('tb-loading');
          showError('Erreur de chargement des créneaux.');
        });
    }

    function submit() {
      if (!state.start) { showError('Choisissez un créneau.'); return; }
      var form = root.querySelector('#tb-form');
      var data = {
        service: service,
        start: state.start,
        customer_name: form.querySelector('[name=customer_name]').value.trim(),
        customer_email: form.querySelector('[name=customer_email]').value.trim(),
        customer_phone: form.querySelector('[name=customer_phone]').value.trim(),
        customer_address: form.querySelector('[name=customer_address]').value.trim(),
        consent: form.querySelector('[name=consent]').checked,
        website: form.querySelector('[name=website]').value,
      };
      root.classList.add('tb-loading');
      fetch(rest + 'bookings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce || '',
        },
        body: JSON.stringify(data),
      })
        .then(function (r) {
          return r.json().then(function (body) { return { status: r.status, body: body }; });
        })
        .then(function (res) {
          root.classList.remove('tb-loading');
          if (res.status >= 200 && res.status < 300) {
            root.innerHTML = '';
            var ok = el('div', 'tb-success');
            ok.textContent = 'Demande envoyée ! Nous reviendrons vers vous très vite.';
            root.append(ok);
            return;
          }
          if (res.status === 422) {
            var errs = (res.body && res.body.data && res.body.data.errors) || {};
            showError(Object.values(errs).join(' ') || 'Champs invalides.');
            return;
          }
          if (res.status === 409) {
            showError('Désolé, ce créneau vient d’être pris. Choisissez-en un autre.');
            loadSlots();
            return;
          }
          showError(res.body && res.body.message ? res.body.message : 'Erreur inconnue.');
        })
        .catch(function () {
          root.classList.remove('tb-loading');
          showError('Impossible d’envoyer la demande.');
        });
    }

    function showError(msg) {
      var fb = root.querySelector('#tb-feedback');
      fb.innerHTML = '';
      var e = el('div', 'tb-error');
      e.textContent = msg;
      fb.append(e);
    }

    function field(name, label, type) {
      var wrap = el('label', 'tb-field');
      var lbl = el('span');
      lbl.textContent = label;
      var input = type === 'textarea' ? el('textarea') : el('input');
      if (type !== 'textarea') input.type = type;
      input.name = name;
      input.required = name !== 'website';
      wrap.append(lbl, input);
      return wrap;
    }

    function labelFor(name) {
      return ({
        customer_name: 'Nom complet',
        customer_email: 'E-mail',
        customer_phone: 'Téléphone',
        customer_address: 'Adresse du rendez-vous',
      })[name] || name;
    }

    function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }
    function h3(t) { var n = el('h3'); n.textContent = t; return n; }
    function text(t) { return document.createTextNode(t); }
    function todayISO() { var d = new Date(); return d.toISOString().slice(0, 10); }
    function addDays(iso, n) { var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
    function formatTime(iso) {
      try { return new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }); }
      catch (e) { return iso; }
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.tb-widget').forEach(init);
  });
})();
```

- [ ] **Step 3: Test manuel (à documenter)**

Documentation pour l'engineer :

```
# Test manuel après cette tâche :
1. Sur un site WP de dev, activer le plugin trinity-booking
2. Créer une page, ajouter le shortcode : [trinity_booking service="pv"]
3. Ouvrir la page → widget visible, choisir une date, choisir un créneau, remplir, soumettre
4. Vérifier en SQL : SELECT id, public_uid, status, customer_email FROM wp_tb_bookings ORDER BY id DESC LIMIT 1;
5. Refaire la même demande → message "créneau plus dispo"
```

- [ ] **Step 4: Commit**

```bash
git add src/PublicFront/assets/booking.js src/PublicFront/assets/booking.css
git commit -m "feat(public-front): add minimal booking widget JS/CSS"
```

---

## Task 30 : Test E2E intégration — parcours complet

**Files:**
- Modify: `tests/Integration/PublicBookingControllerTest.php` (ajouter un test scénario complet)

Vérifie qu'un visiteur peut faire le parcours complet via REST (équivalent du widget JS) : list services → get availability → post booking → résultat 201.

- [ ] **Step 1: Ajouter le test**

Append :

```php
    public function test_end_to_end_booking_flow(): void
    {
        // 1. services
        $r = new WP_REST_Request('GET', '/trinity-booking/v1/services');
        $services = rest_do_request($r)->get_data();
        self::assertNotEmpty($services);

        // 2. availability
        $r = new WP_REST_Request('GET', '/trinity-booking/v1/availability');
        $r->set_query_params([
            'service' => 'pv',
            'from'    => '2026-06-15',
            'to'      => '2026-06-16',
        ]);
        $av = rest_do_request($r);
        self::assertSame(200, $av->get_status());
        $slots = $av->get_data()['slots'];
        self::assertNotEmpty($slots);

        // 3. booking
        $r = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
        $r->set_header('content-type', 'application/json');
        $r->set_body(json_encode([
            'service' => 'pv',
            'start'   => $slots[0]['start'],
            'customer_name' => 'Jean E2E',
            'customer_email' => 'e2e@test.fr',
            'customer_phone' => '0600000000',
            'customer_address' => '1 rue Z, 75001 Paris',
            'consent' => true,
        ]));
        $resp = rest_do_request($r);
        self::assertSame(201, $resp->get_status());
        $uid = $resp->get_data()['public_uid'];

        // 4. plus dispo
        $av2 = rest_do_request($r = new WP_REST_Request('GET', '/trinity-booking/v1/availability'));
        $r->set_query_params(['service' => 'pv', 'from' => '2026-06-15', 'to' => '2026-06-16']);
        $av2 = rest_do_request($r);
        $newSlots = $av2->get_data()['slots'];
        $newStarts = array_column($newSlots, 'start');
        self::assertNotContains($slots[0]['start'], $newStarts, 'Slot still available after booking');

        // 5. cancel via HMAC
        $exp = time() + 3600;
        $sig = $this->signCancel($uid, $exp);
        $cancelReq = new WP_REST_Request('GET', '/trinity-booking/v1/cancel');
        $cancelReq->set_query_params(['uid' => $uid, 'exp' => $exp, 'sig' => $sig]);
        self::assertSame(200, rest_do_request($cancelReq)->get_status());

        // 6. slot redevient dispo
        $av3 = rest_do_request($r = new WP_REST_Request('GET', '/trinity-booking/v1/availability'));
        $r->set_query_params(['service' => 'pv', 'from' => '2026-06-15', 'to' => '2026-06-16']);
        $av3 = rest_do_request($r);
        $finalStarts = array_column($av3->get_data()['slots'], 'start');
        self::assertContains($slots[0]['start'], $finalStarts);
    }
```

- [ ] **Step 2: Run → vert (tout doit déjà marcher)**

Run: `composer test:integration`

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/PublicBookingControllerTest.php
git commit -m "test(integration): add end-to-end booking flow scenario"
```

---

## Task 31 : Hardening — Rate-limit POST /bookings

**Files:**
- Modify: `src/Http/PublicBookingController.php`

Anti-bot basique : max 5 POST /bookings par minute par IP via transients WP.

- [ ] **Step 1: Test**

Append à `PublicBookingControllerTest.php` :

```php
    public function test_rate_limit_blocks_after_threshold(): void
    {
        $payload = json_encode([
            'service' => 'pv', 'start' => '2026-07-01T07:00:00+00:00',
            'customer_name' => 'A', 'customer_email' => 'a@a.fr',
            'customer_phone' => '0600', 'customer_address' => 'x', 'consent' => true,
        ]);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $blocked = false;
        for ($i = 0; $i < 7; $i++) {
            $r = new WP_REST_Request('POST', '/trinity-booking/v1/bookings');
            $r->set_header('content-type', 'application/json');
            $r->set_body($payload);
            $resp = rest_do_request($r);
            if ($resp->get_status() === 429) { $blocked = true; break; }
        }
        self::assertTrue($blocked, 'Rate limiter did not kick in within 7 attempts');
    }
```

- [ ] **Step 2: Run → fail**

- [ ] **Step 3: Implémentation**

Au début de `PublicBookingController::createBooking()`, après le honeypot, ajouter :

```php
        if ($this->isRateLimited()) {
            return new WP_Error('tb_rate_limited', 'Trop de requêtes', ['status' => 429]);
        }
```

Ajouter la méthode :

```php
    private function isRateLimited(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return false;
        }
        $key = 'tb_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 5) {
            return true;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return false;
    }
```

- [ ] **Step 4: Run → vert**

- [ ] **Step 5: Commit**

```bash
git add src/Http/PublicBookingController.php tests/Integration/PublicBookingControllerTest.php
git commit -m "feat(security): rate-limit POST /bookings to 5 req/min/IP"
```

---

## Task 32 : i18n — Préparer le text-domain

**Files:**
- Create: `languages/.gitkeep`
- Modify: `src/Plugin.php`

Charger le text-domain à `init`.

- [ ] **Step 1: Ajouter la méthode `loadTextdomain` dans `Plugin`**

Dans `Plugin::register()` :

```php
        add_action('init', [$this, 'loadTextDomain']);
```

Ajouter :

```php
    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages'
        );
    }
```

- [ ] **Step 2: Créer le dossier `languages/`**

```bash
mkdir -p languages
touch languages/.gitkeep
```

- [ ] **Step 3: Commit**

```bash
git add src/Plugin.php languages/.gitkeep
git commit -m "feat(i18n): load text domain on init"
```

---

## Task 33 : Spec self-review final — Plan 1

À ce stade, vérifier :

- [ ] **Step 1: Lancer la suite complète**

Run: `composer test && composer test:integration && composer stan && composer cs`
Expected: tout vert.

- [ ] **Step 2: Test manuel sur installation WP locale**

Documentation :

```
1. Cloner le plugin dans wp-content/plugins/
2. composer install --no-dev (ou install puis dump-autoload --no-dev pour la prod)
3. Activer le plugin dans /wp-admin/plugins.php
4. Vérifier en SQL : SELECT * FROM wp_tb_services; → 2 lignes (pv, irve)
5. Créer une page contenant : [trinity_booking service="pv"]
6. Ouvrir la page (non connecté) → widget visible
7. Choisir une date demain, créneau → soumettre → "Demande envoyée"
8. SQL : SELECT * FROM wp_tb_bookings; → 1 ligne status=pending
9. Refaire le même créneau → "Désolé, ce créneau vient d'être pris"
```

- [ ] **Step 3: Mise à jour `README.md` avec quickstart**

Ajouter à `README.md` :

```markdown
## Quickstart (V1 minimal)

1. Installer en tant que plugin WordPress (avec `vendor/` inclus).
2. Activer dans `/wp-admin/plugins.php`.
3. Sur une page, ajouter le shortcode :

   ```
   [trinity_booking service="pv"]
   [trinity_booking service="irve"]
   ```

4. Les RDV créés depuis le front sont enregistrés en base avec statut `pending`.

> ⚠️ Le Plan 1 ne couvre pas encore : e-mails, validation admin, sync Google Calendar, dashboard React. Voir `docs/superpowers/plans/` pour la roadmap.
```

- [ ] **Step 4: Commit final**

```bash
git add README.md
git commit -m "docs: add quickstart for Plan 1 milestone"
```

---

## Definition of Done — Plan 1

Plan 1 est terminé quand **toutes** les conditions sont remplies :

- [ ] La suite `composer test` (unit) passe à 100 %.
- [ ] La suite `composer test:integration` passe à 100 % (skippée si WP test suite absente).
- [ ] `composer stan` retourne 0 erreur.
- [ ] `composer cs` retourne 0 erreur.
- [ ] Sur une install WP de dev :
  - Activation du plugin crée les 6 tables et les 2 services PV/IRVE.
  - Le shortcode `[trinity_booking]` rend un formulaire fonctionnel.
  - Un visiteur peut prendre un RDV de bout en bout.
  - Un second visiteur ne peut pas réserver le même créneau (409).
  - Un lien d'annulation HMAC fonctionne (testé via curl ou test E2E).
- [ ] CI verte sur PHP 8.1/8.2/8.3.

À la fin de ce plan : **on a un système de réservation fonctionnel basique, sans e-mails ni Google.** Les prochains plans (2 à 5) construisent sur cette base.

---

## Self-Review (effectué par l'auteur du plan)

**Couverture du spec :**

| Spec section | Tâche(s) couvrant |
| --- | --- |
| §2 périmètre V1 — services PV/IRVE | T13 (seed), T9 (entité) |
| §2 formulaire shortcode | T28, T29 |
| §2 règles (durée, buffer, lead, horizon, horaires) | T9 (Service), T17 (SlotGenerator), T18 (Calc) |
| §2 statuts | T7 (enum), T10 (Booking transitions) |
| §2 annulation client HMAC | T20 (signer), T22 (use case), T27 (endpoint) |
| §3 stack | T2, T3, T4, T5 |
| §4 modules Domain/Persistence/Availability/Booking/Http/PublicFront | T7-T29 |
| §5 schéma DB | T12 (Migrator) |
| §6.1 création booking | T21 + T26 |
| §6.4 cancel client | T22 + T27 |
| §9 sécurité (HMAC, honeypot, rate-limit) | T20, T26, T31 |
| §11 tests unit/integration | dans toutes les tâches |
| §13 i18n | T32 |
| §14 activation | T13 |

**Hors-scope explicite (couverts par Plans 2-5) :**

- §2 e-mails → Plan 2
- §2 validation admin (e-mail HMAC + dashboard) → Plan 2
- §2 sync Google bidirectionnelle → Plans 3-4
- §2 dashboard React, templates editor → Plans 2/5
- §2 WP-CLI → Plan 5
- §2 reminders J-1 → Plan 2
- §10 RGPD exporters/erasers → Plan 5
- §12 observabilité → Plans 3-4 (avec la sync) + 5

**Placeholders :** aucun. Tous les steps contiennent le code complet.

**Cohérence des types :** méthodes croisées vérifiées — `BookingRepository::findByPublicUid` (T15) utilisée par `PublicCancelController` (T27) et `CancelBooking` (T22). `Service::id` accessible (Task 9) utilisé partout. `TimeSlot::overlaps` (T8) utilisé dans `AvailabilityCalculator` (T18). `Plugin::REST_NAMESPACE` (T6) utilisé dans tous les controllers.

**Découpage des fichiers :** chaque fichier `src/` a une responsabilité unique et < 250 lignes. `BookingRepository` est le plus volumineux (~140 lignes) — acceptable car responsabilité homogène (mapping objet ↔ DB).
