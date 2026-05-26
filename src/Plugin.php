<?php
declare(strict_types=1);

namespace Slash\Booking;

final class Plugin
{
    public const VERSION = '1.0.23';
    public const TEXT_DOMAIN = 'slashbooking';
    public const DB_VERSION = 1;
    public const REST_NAMESPACE = 'slashbooking/v1';

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
        // Plugin::register() runs at plugin sandbox scrape, BEFORE the
        // register_activation_hook fires. Without this idempotent seed, a fresh
        // install fatals on `new DecisionTokenSigner('')` further down.
        Activator::ensureDecisionSecret();

        // No is_admin() guard: PUC's filters need to be registered in any
        // context that may refresh the update_plugins transient — including
        // WP-Cron (DOING_CRON=true, is_admin()=false). Skipping bootstrap on
        // those contexts left the transient stale and made updates undetectable.
        Updates\UpdateChecker::bootstrap($this->pluginFile);

        // Propagate capability changes to existing installs that were activated
        // under an older revision (e.g. before editor role got plugin access).
        Admin\Capabilities::syncOnUpgrade();

        $router = new Http\RestRouter();
        $router->register();
        $this->set(Http\RestRouter::class, $router);

        global $wpdb;
        $services = new Persistence\ServiceRepository($wpdb);
        $shortcode = new PublicFront\Shortcode($services);
        $shortcode->register();

        $bookings = new Persistence\BookingRepository($wpdb);

        $reminder = new Notifications\ReminderScheduler($bookings);
        $reminder->register();

        // Plan 5: WP privacy data exporters / erasers
        $privacyExporter = new Privacy\BookingExporter(
            findByEmail: fn (string $email) => $bookings->findByCustomerEmail($email),
        );
        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters) use ($privacyExporter): array {
            $exporters['slashbooking'] = [
                'exporter_friendly_name' => __('SlashBooking', 'slashbooking'),
                'callback'               => static fn (string $email, int $page = 1) => $privacyExporter->export($email, $page),
            ];
            return $exporters;
        });

        $privacyEraser = new Privacy\BookingEraser(
            anonymizeByEmail: fn (string $email) => $bookings->anonymizeByEmail($email),
        );
        add_filter('wp_privacy_personal_data_erasers', static function (array $erasers) use ($privacyEraser): array {
            $erasers['slashbooking'] = [
                'eraser_friendly_name' => __('SlashBooking', 'slashbooking'),
                'callback'             => static fn (string $email, int $page = 1) => $privacyEraser->erase($email, $page),
            ];
            return $erasers;
        });

        // Cron interval must also be present at runtime, not just at activation.
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['sb_monthly'])) {
                $s['sb_monthly'] = [
                    'interval' => 2_592_000,
                    'display'  => 'Once every 30 days (SlashBooking)',
                ];
            }
            return $s;
        });

        Privacy\BookingRetentionPurger::register();

        $signer = new Booking\DecisionTokenSigner((string) get_option('sb_decision_secret'));
        // Lazy URL resolver — rest_url() requires $wp_rewrite which is not yet
        // initialized at plugin file load time. The closure fires later, when
        // BookingNotifier callbacks actually need to build a URL.
        $urls = new Http\UrlBuilder($signer, fn (): string => rest_url(self::REST_NAMESPACE));

        $dispatcher = new Notifications\MailDispatcher(
            new Persistence\MailTemplateRepository($wpdb),
            new Notifications\TemplateRenderer(new Notifications\TagRegistry()),
            new Notifications\TextBodyGenerator(),
            new Notifications\IcsBuilder(),
        );
        $this->set(Notifications\MailDispatcher::class, $dispatcher);

        (new Notifications\BookingNotifier(
            $services,
            $bookings,
            $dispatcher,
            $urls,
        ))->register();

        // ----- Google sync (Plan 3) -----
        $accounts    = new Persistence\GoogleAccountRepository($wpdb);
        $syncLogRepo = new Persistence\SyncLogRepository($wpdb);
        $keyResolver = new Google\EncryptionKeyResolver();
        $encryption  = new Google\Encryption($keyResolver->resolve());

        $pendingColor   = (string) get_option('sb_gcal_color_pending', '6');
        $confirmedColor = (string) get_option('sb_gcal_color_confirmed', '10');
        $formatter      = new Google\EventFormatter($pendingColor, $confirmedColor);

        (new Google\PushScheduler())->register();

        (new Google\SyncLogPurger(
            purge: fn (\DateTimeImmutable $cutoff): int => $syncLogRepo->purgeOlderThan($cutoff),
        ))->register();

        $clientBuilder = new Google\GoogleClientBuilder($encryption, $accounts);

        // Register Action Scheduler handler.
        add_action(Google\PushScheduler::HOOK, function (int $bookingId, string $action) use (
            $bookings,
            $services,
            $accounts,
            $syncLogRepo,
            $formatter,
            $clientBuilder
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
                    level: 'warn',
                    direction: 'wp_to_g',
                    entity: 'booking',
                    entityId: $bookingId,
                    googleEventId: null,
                    action: $action,
                    status: 'failed',
                    payload: [],
                    errorMessage: 'No Google account connected',
                );
                return;
            }

            try {
                $gateway = $clientBuilder->buildGateway($account);
            } catch (\Throwable $e) {
                $syncLogRepo->append(
                    level: 'error',
                    direction: 'wp_to_g',
                    entity: 'booking',
                    entityId: $bookingId,
                    googleEventId: $booking->googleEventId(),
                    action: $action,
                    status: 'failed',
                    payload: [],
                    errorMessage: 'Token refresh failed: ' . $e->getMessage(),
                );
                return;
            }

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

        // ----- Google sync inbound (Plan 4) -----
        $busyRepo = new Persistence\BusyBlockRepository($wpdb);
        $watchMgr = new Google\WatchChannelManager(
            persist: fn (Domain\GoogleAccount $a) => $accounts->save($a),
            ttlSeconds: 604_800, // 7 days
        );

        // Custom 15-minute cron interval. Filter must be present at every boot
        // (cron itself fires from WP-Cron which calls these filters at runtime).
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['sb_fifteen_minutes'])) {
                $s['sb_fifteen_minutes'] = [
                    'interval' => 900,
                    'display'  => 'Every 15 minutes (SlashBooking)',
                ];
            }
            return $s;
        });

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

        // Action Scheduler handler for inbound pulls.
        // Producers (webhook controller, admin "pull now", cron fallback) call:
        //   as_schedule_single_action(time() + 5, 'sb/google_pull', [$accountId], 'slashbooking')
        // RestRouter constructs its own enqueuePull closure with the same hook name — keeps the
        // producer-side wiring close to the controllers without dragging Plugin.php into them.
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

        // Daily watch renewal check: renew if expiration < now+1 day.
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
                $gateway    = $clientBuilder->buildGateway($account);
                $webhookUrl = rest_url(Plugin::REST_NAMESPACE . '/google/webhook');
                $watchMgr->renew($account, $gateway, $webhookUrl);
                $syncLogRepo->append(
                    level: 'info',
                    direction: 'internal',
                    entity: 'watch',
                    entityId: $account->id(),
                    googleEventId: null,
                    action: 'watch_renewed',
                    status: 'ok',
                    payload: ['channelId' => $account->watchChannelId()],
                    errorMessage: null,
                );
            } catch (\Throwable $e) {
                $syncLogRepo->append(
                    level: 'error',
                    direction: 'internal',
                    entity: 'watch',
                    entityId: $account->id(),
                    googleEventId: null,
                    action: 'watch_renew',
                    status: 'failed',
                    payload: [],
                    errorMessage: $e->getMessage(),
                );
            }
        });

        // 15-min cron fallback: enqueue one pull per account (V1: a single account).
        add_action('sb/google_pull_all', static function () use ($accounts): void {
            $account = $accounts->findSingle();
            if ($account === null) {
                return;
            }
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'sb/google_pull', [(int) $account->id()], 'slashbooking');
                return;
            }
            do_action('sb/google_pull', (int) $account->id());
        });

        // Admin notice if encryption key falls back to option.
        if ($keyResolver->usingFallback()) {
            add_action('admin_notices', function (): void {
                if (!current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-warning"><p><strong>SlashBooking :</strong> définissez <code>SLASHBOOKING_ENC_KEY</code> dans <code>wp-config.php</code> pour chiffrer les tokens Google avec une clé hors base.</p></div>';
            });
        }

        (new Admin\AdminMenu())->register();
        (new Admin\Assets($this))->register();
        (new Admin\DashboardWidget($bookings, $services))->register();

        if (defined('WP_CLI') && WP_CLI) {
            $pullNow = static function (Domain\GoogleAccount $account) use ($clientBuilder, $buildSyncEngine): Google\PullResult {
                $gateway = $clientBuilder->buildGateway($account);
                return $buildSyncEngine()->pull($account, $gateway);
            };
            /** @phpstan-ignore-next-line Class.NotFound (WP_CLI conditionally available) */
            \WP_CLI::add_command('slashbooking doctor', new Cli\DoctorCommand(
                $accounts,
                $clientBuilder,
                $pullNow,
            ));
        }

        add_action('init', [$this, 'loadTextDomain']);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages'
        );
    }
}
