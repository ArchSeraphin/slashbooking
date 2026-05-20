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

        (new Admin\AdminMenu())->register();

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
