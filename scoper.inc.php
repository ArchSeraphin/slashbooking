<?php
declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Slash\\Booking\\Vendor',

    'finders' => [
        // 1) Tout le code source du plugin.
        Finder::create()
            ->files()
            ->in('src')
            ->name('*.php'),

        // 2) Composer vendors qu'on veut isoler (Google API client + dépendances transitives).
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile|composer\\.json|composer\\.lock/')
            ->exclude([
                'doc',
                'test',
                'test_old',
                'tests',
                'Tests',
                'vendor-bin',
            ])
            ->in('vendor/google')
            ->in('vendor/guzzlehttp')
            ->in('vendor/psr')
            ->in('vendor/firebase')
            ->in('vendor/monolog')
            // ->in('vendor/paragonie')   // n'existe pas avec google/apiclient v2.19.3 — réactiver si une version future le réintroduit
            // ->in('vendor/phpseclib')   // idem
            ->in('vendor/ralouphie')
            ->in('vendor/symfony')
            ->in('vendor/yahnis-elsts')
            ->name('*.php'),

        // 3) Composer autoloader files (pour que le build régénère un autoload classmap propre).
        Finder::create()
            ->append([
                'vendor/google/apiclient/composer.json',
                'vendor/guzzlehttp/guzzle/composer.json',
            ]),
    ],

    // Ne PAS prefixer ces namespaces : ils restent globaux.
    'exclude-namespaces' => [
        // WordPress n'a pas de namespace formel, mais on protège les classes globales connues.
        'Slash\\Booking',          // notre code à nous, jamais préfixé
        'PHPUnit',
        'Composer',
        'Symfony\\Polyfill',         // polyfills doivent rester globaux
    ],

    // Classes globales WordPress / PHP à ne pas réécrire.
    'exclude-classes' => [
        'wpdb',
        'WP_Error',
        'WP_REST_Request',
        'WP_REST_Response',
        'WP_REST_Server',
        'WP_User',
        'WP_Query',
        'WP_Post',
        'ActionScheduler',
        'ActionScheduler_DBStoreMigrator',
        'WP_CLI',
        '/^Google_/',                // anciens alias de la lib Google (Google_Client, etc.)
    ],

    // Fonctions globales : __, esc_html, get_option, etc. Aucune ne doit être prefixée.
    'exclude-functions' => [
        '/^wp_/',
        '/^get_/',
        '/^add_/',
        '/^update_/',
        '/^delete_/',
        '/^do_action/',
        '/^apply_filters/',
        '/^esc_/',
        '/^sanitize_/',
        '/^is_/',
        '/^current_/',
        '/^current_user_can/',
        '/^register_/',
        '/^rest_/',
        '/^plugin_/',
        '__',
        '_e',
        '_x',
        '_n',
        '_nx',
        'load_plugin_textdomain',
        'as_schedule_single_action',
        'as_unschedule_all_actions',
        'as_next_scheduled_action',
    ],

    // Constantes globales WP à exclure.
    'exclude-constants' => [
        '/^WP_/',
        'ABSPATH',
        'WPINC',
        'ARRAY_A',
        'ARRAY_N',
        'OBJECT',
        'OBJECT_K',
    ],

    // Patchers : interventions ciblées pour les libs qui font des choses non-standard.
    'patchers' => [
        // Google\Client utilise `class_exists('GuzzleHttp\\Client')` en string : prefixons-la.
        static function (string $filePath, string $prefix, string $content): string {
            if (str_contains($filePath, 'vendor/google/apiclient/src/Client.php')) {
                $content = str_replace(
                    "class_exists('GuzzleHttp\\\\Client')",
                    "class_exists('{$prefix}\\\\GuzzleHttp\\\\Client')",
                    $content,
                );
            }
            return $content;
        },
        // Plugin Update Checker fait une dispatch interne par concaténation de
        // strings à runtime : `'Vcs\\' . $type . 'UpdateChecker'`. Scoper ne
        // voit pas ces strings construites dynamiquement et ne les préfixe
        // pas, alors qu'il a préfixé les clés du registre dans load-v5p6.php
        // — résultat : lookup miss et fatal "PUC does not support updates for
        // plugins hosted on GitHub". Revert les clés à leur forme originale.
        static function (string $filePath, string $prefix, string $content): string {
            if (!str_contains($filePath, 'plugin-update-checker/load-v5p6.php')) {
                return $content;
            }
            return str_replace(
                [
                    "'{$prefix}\\Plugin\\UpdateChecker'",
                    "'{$prefix}\\Theme\\UpdateChecker'",
                    "'{$prefix}\\Vcs\\PluginUpdateChecker'",
                    "'{$prefix}\\Vcs\\ThemeUpdateChecker'",
                ],
                [
                    "'Plugin\\UpdateChecker'",
                    "'Theme\\UpdateChecker'",
                    "'Vcs\\PluginUpdateChecker'",
                    "'Vcs\\ThemeUpdateChecker'",
                ],
                $content,
            );
        },
    ],
];
