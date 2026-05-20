<?php

declare(strict_types=1);

namespace Trinity\Booking\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Google\GoogleClientBuilder;
use Trinity\Booking\Persistence\GoogleAccountRepository;

final class DoctorCommand
{
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly GoogleClientBuilder $clientBuilder,
    ) {
    }

    /**
     * Run diagnostics: OAuth state, token refresh, calendar reachability.
     *
     * ## EXAMPLES
     *
     *     wp trinity-booking doctor
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        \WP_CLI::log('🩺 trinity-booking doctor');
        \WP_CLI::log('—————————————————');

        $account = $this->accounts->findSingle();
        if ($account === null) {
            \WP_CLI::warning('No Google account connected. Run OAuth flow from admin.');
            return;
        }

        \WP_CLI::success(sprintf(
            'Account: %s (calendar=%s, expires=%s)',
            $account->label(),
            $account->calendarId(),
            $account->expiresAt()->format(\DateTimeInterface::ATOM),
        ));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now)) {
            \WP_CLI::log('Access token expired — attempting refresh…');
        }

        try {
            $gateway = $this->clientBuilder->buildGateway($account);
        } catch (\Throwable $e) {
            \WP_CLI::error('Failed to build Google client: ' . $e->getMessage());
        }
        \WP_CLI::success('Google client built (token refresh OK if needed).');

        $probe = [
            'summary'     => '[trinity-booking doctor probe — safe to delete]',
            'description' => 'Self-test event. Will be deleted immediately.',
            'start'       => ['dateTime' => $now->modify('+1 hour')->format('Y-m-d\TH:i:sP'), 'timeZone' => 'UTC'],
            'end'         => ['dateTime' => $now->modify('+2 hours')->format('Y-m-d\TH:i:sP'), 'timeZone' => 'UTC'],
        ];
        try {
            $ref = $gateway->insertEvent($account->calendarId(), $probe);
            \WP_CLI::success('Inserted probe event ' . $ref['id']);
            $gateway->deleteEvent($account->calendarId(), $ref['id']);
            \WP_CLI::success('Deleted probe event ' . $ref['id']);
        } catch (\Throwable $e) {
            \WP_CLI::error('Probe failed: ' . $e->getMessage());
        }
    }
}
