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
            $GLOBALS['tb_enqueue_needed'] = true; // @phpstan-ignore-line
        }
    }

    private function shouldEnqueue(): bool
    {
        return !empty($GLOBALS['tb_enqueue_needed']); // @phpstan-ignore-line
    }
}
