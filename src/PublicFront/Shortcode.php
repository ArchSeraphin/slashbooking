<?php
declare(strict_types=1);

namespace Slash\Booking\PublicFront;

use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;

final class Shortcode
{
    public function __construct(private readonly ServiceRepository $services)
    {
    }

    public function register(): void
    {
        add_shortcode('slashbooking', [$this, 'render']);
    }

    /**
     * @param array<string, string>|string $attrs
     */
    public function render($attrs): string
    {
        $attrs = is_array($attrs) ? $attrs : [];

        // The shortcode supports three forms:
        //   [slashbooking]                   -> user picks service in the widget (all active services)
        //   [slashbooking service="pv"]      -> service forced (no picker step)
        //   [slashbooking service="pv,irve"] -> picker filtered to a whitelist
        $rawService = isset($attrs['service']) ? sanitize_text_field((string) $attrs['service']) : '';
        $slugs = array_values(array_filter(array_map(
            static fn (string $s): string => preg_replace('/[^a-z0-9_\-]/i', '', trim($s)) ?? '',
            $rawService === '' ? [] : explode(',', $rawService)
        )));

        // If a single slug is provided, validate it. Any other case is left to the widget.
        if (count($slugs) === 1) {
            $svc = $this->services->findBySlug($slugs[0]);
            if ($svc === null || !$svc->isActive()) {
                return '<div class="sb-error">' . esc_html__('Service inconnu', 'slashbooking') . '</div>';
            }
            $serviceAttr = $svc->slug;
        } else {
            // Empty (= all services) or whitelist (= "pv,irve") — widget will fetch /services to render the picker.
            $serviceAttr = implode(',', $slugs);
        }

        // Enqueue assets directly here — WP queues them and prints in footer.
        $this->enqueueAssets();

        return sprintf(
            '<div class="sb-widget" data-sb-service="%s" data-sb-rest="%s"></div>',
            esc_attr($serviceAttr),
            esc_url_raw(rest_url(Plugin::REST_NAMESPACE . '/')),
        );
    }

    private function enqueueAssets(): void
    {
        $pluginUrl = plugin_dir_url(Plugin::instance()->pluginFile());

        wp_enqueue_style(
            'slashbooking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.css',
            [],
            Plugin::VERSION
        );

        $turnstileKey = (string) get_option('sb_turnstile_site_key', '');
        $deps         = [];

        if ($turnstileKey !== '') {
            // Cloudflare Turnstile API — explicit render lets us insert the widget
            // after the booking form is built by booking.js.
            wp_enqueue_script(
                'sb-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
                [],
                null,
                true
            );
            $deps[] = 'sb-turnstile';
        }

        wp_enqueue_script(
            'slashbooking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.js',
            $deps,
            Plugin::VERSION,
            true
        );

        $legalId  = (int) get_option('sb_legal_page_id', 0);
        $legalUrl = $legalId > 0 ? (string) get_permalink($legalId) : '';

        wp_localize_script('slashbooking-public', 'SlashBooking', [
            'nonce'           => wp_create_nonce('wp_rest'),
            'locale'          => get_locale(),
            'legalUrl'        => $legalUrl,
            'disclaimer'      => (string) get_option('sb_form_disclaimer', ''),
            'turnstileSiteKey'=> $turnstileKey,
        ]);
    }
}
