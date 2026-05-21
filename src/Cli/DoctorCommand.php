<?php

declare(strict_types=1);

namespace Slash\Booking\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Closure;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\GoogleClientBuilder;
use Slash\Booking\Google\PullResult;
use Slash\Booking\Persistence\GoogleAccountRepository;

final class DoctorCommand
{
    /**
     * @param Closure(GoogleAccount): PullResult $pullNow
     */
    public function __construct(
        private readonly GoogleAccountRepository $accounts,
        private readonly GoogleClientBuilder $clientBuilder,
        private readonly Closure $pullNow,
    ) {
    }

    /**
     * Run diagnostics: OAuth state, token refresh, calendar reachability.
     *
     * ## EXAMPLES
     *
     *     wp slashbooking doctor
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        \WP_CLI::log('🩺 slashbooking doctor');
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
            'summary'     => '[slashbooking doctor probe — safe to delete]',
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

        \WP_CLI::log('—————————————————');
        if ($account->watchChannelId() === null) {
            \WP_CLI::warning('No watch channel registered. Run "Démarrer le watch" from the admin UI to enable inbound sync.');
        } else {
            \WP_CLI::success(sprintf(
                'Watch channel: %s (expires=%s)',
                $account->watchChannelId(),
                $account->watchExpiresAt()?->format(\DateTimeInterface::ATOM) ?? 'unknown',
            ));
        }

        \WP_CLI::log('Performing test pull…');
        try {
            $result = ($this->pullNow)($account);
            \WP_CLI::success(sprintf(
                'Pull OK: %d upserted / %d deleted / %d reflection-ignored',
                $result->upserted,
                $result->deleted,
                $result->ignoredReflection,
            ));
        } catch (\Throwable $e) {
            \WP_CLI::error('Pull failed: ' . $e->getMessage());
        }
    }
}
