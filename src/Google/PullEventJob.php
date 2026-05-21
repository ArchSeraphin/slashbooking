<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Closure;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\GoogleApiError;
use Slash\Booking\Google\Exceptions\GoogleClientError;
use Slash\Booking\Google\Exceptions\OAuthFailure;

final class PullEventJob
{
    /**
     * @param Closure(int): ?GoogleAccount                              $findAccount
     * @param Closure(GoogleAccount): CalendarGateway                  $buildGateway
     * @param Closure(GoogleAccount, CalendarGateway): PullResult      $pull
     * @param Closure(array<string, mixed>): void                      $log
     */
    public function __construct(
        private readonly Closure $findAccount,
        private readonly Closure $buildGateway,
        private readonly Closure $pull,
        private readonly Closure $log,
    ) {
    }

    public function handle(int $accountId): void
    {
        $account = ($this->findAccount)($accountId);
        if ($account === null) {
            $this->logEntry('error', $accountId, 'pull', 'failed', 'account not found');
            return;
        }

        try {
            $gateway = ($this->buildGateway)($account);
        } catch (OAuthFailure $e) {
            $this->logEntry('error', $accountId, 'pull', 'failed', 'token refresh failed: ' . $e->getMessage());
            return;
        }

        try {
            ($this->pull)($account, $gateway);
        } catch (GoogleClientError $e) {
            $this->logEntry('error', $accountId, 'pull', 'failed', '4xx: ' . $e->getMessage());
        } catch (GoogleApiError $e) {
            $this->logEntry('warn', $accountId, 'pull', 'retry', '5xx/429: ' . $e->getMessage());
            throw $e;
        }
    }

    private function logEntry(string $level, int $accountId, string $action, string $status, ?string $error): void
    {
        ($this->log)([
            'level'           => $level,
            'direction'       => 'g_to_wp',
            'entity'          => 'busy_block',
            'entity_id'       => $accountId,
            'google_event_id' => null,
            'action'          => $action,
            'status'          => $status,
            'error_message'   => $error,
            'payload'         => [],
        ]);
    }
}
