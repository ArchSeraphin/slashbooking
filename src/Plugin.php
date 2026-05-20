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

        $signer = new Booking\DecisionTokenSigner((string) get_option('tb_decision_secret'));
        $urls   = new Http\UrlBuilder($signer, rest_url(self::REST_NAMESPACE));

        $dispatcher = new Notifications\MailDispatcher(
            new Persistence\MailTemplateRepository($wpdb),
            new Notifications\TemplateRenderer(new Notifications\TagRegistry()),
            new Notifications\TextBodyGenerator(),
            new Notifications\IcsBuilder(),
        );

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

        $pendingColor   = (string) get_option('tb_gcal_color_pending', '6');
        $confirmedColor = (string) get_option('tb_gcal_color_confirmed', '10');
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

        // Admin notice if encryption key falls back to option.
        if ($keyResolver->usingFallback()) {
            add_action('admin_notices', function (): void {
                if (!current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-warning"><p><strong>Trinity Booking :</strong> définissez <code>TRINITY_BOOKING_ENC_KEY</code> dans <code>wp-config.php</code> pour chiffrer les tokens Google avec une clé hors base.</p></div>';
            });
        }

        (new Admin\AdminMenu())->register();
        (new Admin\Assets($this))->register();

        if (defined('WP_CLI') && WP_CLI) {
            /** @phpstan-ignore-next-line Class.NotFound (WP_CLI conditionally available) */
            \WP_CLI::add_command('trinity-booking doctor', new Cli\DoctorCommand(
                $accounts,
                $clientBuilder,
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
