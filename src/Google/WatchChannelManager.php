<?php
declare(strict_types=1);

namespace Trinity\Booking\Google;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Domain\GoogleAccount;

final class WatchChannelManager
{
    /**
     * @param Closure(GoogleAccount): void $persist
     * @param int                          $ttlSeconds  TTL of the channel (Google max = 604800 = 7 days)
     */
    public function __construct(
        private readonly Closure $persist,
        private readonly int $ttlSeconds = 604_800,
    ) {
    }

    public function start(GoogleAccount $account, CalendarGateway $gateway, string $webhookUrl): void
    {
        if ($account->watchChannelId() !== null && $account->watchResourceId() !== null) {
            $gateway->stopChannel($account->watchChannelId(), $account->watchResourceId());
        }

        $channelId   = self::uuidv4();
        $tokenSecret = bin2hex(random_bytes(16));

        $ref = $gateway->watchChannel(
            calendarId: $account->calendarId(),
            channelId: $channelId,
            address: $webhookUrl,
            token: $tokenSecret,
            ttlSeconds: $this->ttlSeconds,
        );

        $expiresAt = (new DateTimeImmutable('@' . $ref['expiration']))->setTimezone(new DateTimeZone('UTC'));
        $account->attachWatch(
            channelId: $ref['channelId'],
            resourceId: $ref['resourceId'],
            tokenSecret: $tokenSecret,
            expiresAt: $expiresAt,
        );
        ($this->persist)($account);
    }

    public function stop(GoogleAccount $account, CalendarGateway $gateway): void
    {
        $channelId  = $account->watchChannelId();
        $resourceId = $account->watchResourceId();
        if ($channelId === null || $resourceId === null) {
            return;
        }
        $gateway->stopChannel($channelId, $resourceId);
        $account->clearWatch();
        ($this->persist)($account);
    }

    public function renew(GoogleAccount $account, CalendarGateway $gateway, string $webhookUrl): void
    {
        $this->start($account, $gateway, $webhookUrl);
    }

    private static function uuidv4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
