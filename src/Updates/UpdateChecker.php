<?php
declare(strict_types=1);

namespace Slash\Booking\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Wires the Plugin Update Checker library against a public GitHub repository
 * hosting tagged releases. WordPress admin then sees update notifications and
 * "Update now" buttons exactly like for plugins hosted on wp.org.
 *
 * Release flow: pushing a tag `v1.2.3` triggers a GitHub Actions workflow that
 * builds the distribution ZIP and attaches it as a release asset. PUC polls the
 * /releases/latest endpoint, downloads the asset, and lets WP install it.
 */
final class UpdateChecker
{
    public const REPO_URL = 'https://github.com/ArchSeraphin/slashbooking/';
    public const PLUGIN_SLUG = 'slashbooking';
    public const RELEASE_BRANCH = 'main';

    public static function bootstrap(string $pluginFile): void
    {
        if (!class_exists(PucFactory::class)) {
            // PUC autoloader file is loaded once via the library entrypoint.
            $loader = \dirname($pluginFile) . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
            if (!is_readable($loader)) {
                return;
            }
            require_once $loader;
        }

        $checker = PucFactory::buildUpdateChecker(
            self::REPO_URL,
            $pluginFile,
            self::PLUGIN_SLUG,
        );
        $checker->setBranch(self::RELEASE_BRANCH);

        $vcs = $checker->getVcsApi();
        if ($vcs !== null && method_exists($vcs, 'enableReleaseAssets')) {
            // GitHub release asset (the .zip uploaded by the Actions workflow)
            // is the installable artifact — without this, PUC tries to install
            // a source-tree tarball which has no built /vendor.
            $vcs->enableReleaseAssets('/slashbooking-.*\.zip$/i');
        }
    }
}
