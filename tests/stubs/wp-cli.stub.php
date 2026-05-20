<?php

/**
 * Minimal WP-CLI stubs for PHPStan analysis.
 *
 * The real WP_CLI class is loaded by WordPress only during CLI runs,
 * so PHPStan's static analysis can't see it. This stub declares the
 * methods we actually call from Trinity\Booking\Cli.
 *
 * Not autoloaded — referenced via phpstan.neon `scanFiles`.
 */

declare(strict_types=1);

if (!class_exists(\WP_CLI::class)) {
    /**
     * @phpstan-ignore-next-line class.notFound
     */
    class WP_CLI
    {
        public static function log(string $message): void {}
        public static function success(string $message): void {}
        public static function warning(string $message): void {}

        /**
         * @phpstan-return ($exit is true ? never : void)
         */
        public static function error(string $message, bool $exit = true): void {}

        /**
         * @param object|class-string|callable $callable
         * @param array<string, mixed> $args
         */
        public static function add_command(string $name, mixed $callable, array $args = []): bool
        {
            return true;
        }
    }
}
