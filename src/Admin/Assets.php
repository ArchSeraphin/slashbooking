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
        // wp-scripts derives bundle filename from the entry file:
        // src/Admin/react-app/src/index.jsx  →  assets/dist/index.jsx.{js,css,asset.php}
        $assetFile = $dir . '/assets/dist/index.jsx.asset.php';
        if (!is_file($assetFile)) {
            return;
        }
        /** @var array{dependencies:array<string>, version:string} $asset */
        $asset = require $assetFile;

        wp_enqueue_script(
            'slashbooking-admin',
            $url . 'assets/dist/index.jsx.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_enqueue_style(
            'slashbooking-admin',
            $url . 'assets/dist/index.jsx.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_localize_script('slashbooking-admin', 'SlashBooking', [
            'restUrl' => esc_url_raw(rest_url(Plugin::REST_NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => Plugin::VERSION,
        ]);
    }
}
