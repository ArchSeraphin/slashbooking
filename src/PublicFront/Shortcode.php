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

        // Enqueue assets directly here — WP queues them and prints in footer.
        // The previous wp_enqueue_scripts-hook approach failed because that hook
        // fires BEFORE the shortcode renders, so a global flag set in render()
        // is never readable by the hook callback.
        $this->enqueueAssets();

        return sprintf(
            '<div class="tb-widget" data-tb-service="%s" data-tb-rest="%s"></div>',
            esc_attr($svc->slug),
            esc_url_raw(rest_url(Plugin::REST_NAMESPACE . '/')),
        );
    }

    private function enqueueAssets(): void
    {
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

        $legalId  = (int) get_option('tb_legal_page_id', 0);
        $legalUrl = $legalId > 0 ? (string) get_permalink($legalId) : '';

        wp_localize_script('trinity-booking-public', 'TrinityBooking', [
            'nonce'    => wp_create_nonce('wp_rest'),
            'locale'   => get_locale(),
            'legalUrl' => $legalUrl,
        ]);
    }
}
