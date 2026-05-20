<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class GoogleAccount
{
    private function __construct(
        private ?int $id,
        private string $label,
        private string $calendarId,
        private string $refreshTokenEnc,
        private string $accessTokenEnc,
        private DateTimeImmutable $expiresAt,
        private ?string $watchChannelId,
        private ?string $watchResourceId,
        private ?string $watchTokenSecret,
        private ?DateTimeImmutable $watchExpiresAt,
        private ?string $syncToken,
        private ?DateTimeImmutable $lastFullSyncAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function connect(
        string $label,
        string $calendarId,
        string $refreshTokenEnc,
        string $accessTokenEnc,
        DateTimeImmutable $expiresAt,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: null,
            label: $label,
            calendarId: $calendarId,
            refreshTokenEnc: $refreshTokenEnc,
            accessTokenEnc: $accessTokenEnc,
            expiresAt: $expiresAt,
            watchChannelId: null,
            watchResourceId: null,
            watchTokenSecret: null,
            watchExpiresAt: null,
            syncToken: null,
            lastFullSyncAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');
        $parse = static function (?string $s) use ($utc): ?DateTimeImmutable {
            return $s === null ? null : new DateTimeImmutable($s, $utc);
        };
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            calendarId: (string) $row['calendar_id'],
            refreshTokenEnc: (string) ($row['oauth_refresh_token_enc'] ?? ''),
            accessTokenEnc: (string) ($row['oauth_access_token_enc'] ?? ''),
            expiresAt: $parse($row['oauth_expires_at'] ?? null) ?? new DateTimeImmutable('1970-01-01', $utc),
            watchChannelId: $row['watch_channel_id'] !== null ? (string) $row['watch_channel_id'] : null,
            watchResourceId: $row['watch_resource_id'] !== null ? (string) $row['watch_resource_id'] : null,
            watchTokenSecret: $row['watch_token_secret'] !== null ? (string) $row['watch_token_secret'] : null,
            watchExpiresAt: $parse($row['watch_expires_at'] ?? null),
            syncToken: $row['sync_token'] !== null ? (string) $row['sync_token'] : null,
            lastFullSyncAt: $parse($row['last_full_sync_at'] ?? null),
            createdAt: $parse((string) $row['created_at']) ?? new DateTimeImmutable('now', $utc),
            updatedAt: $parse((string) $row['updated_at']) ?? new DateTimeImmutable('now', $utc),
        );
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new \DomainException('GoogleAccount id already assigned.');
        }
        $this->id = $id;
    }

    public function rotateAccessToken(string $accessTokenEnc, DateTimeImmutable $expiresAt): void
    {
        $this->accessTokenEnc = $accessTokenEnc;
        $this->expiresAt = $expiresAt;
        $this->touch();
    }

    public function accessTokenExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function attachWatch(
        string $channelId,
        string $resourceId,
        string $tokenSecret,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->watchChannelId   = $channelId;
        $this->watchResourceId  = $resourceId;
        $this->watchTokenSecret = $tokenSecret;
        $this->watchExpiresAt   = $expiresAt;
        $this->touch();
    }

    public function clearWatch(): void
    {
        $this->watchChannelId   = null;
        $this->watchResourceId  = null;
        $this->watchTokenSecret = null;
        $this->watchExpiresAt   = null;
        $this->touch();
    }

    public function verifyWatchToken(string $candidate): bool
    {
        if ($this->watchTokenSecret === null || $candidate === '') {
            return false;
        }
        return hash_equals($this->watchTokenSecret, $candidate);
    }

    public function updateSyncToken(string $token): void
    {
        $this->syncToken = $token;
        $this->touch();
    }

    public function clearSyncToken(): void
    {
        $this->syncToken = null;
        $this->touch();
    }

    public function markFullSyncedAt(DateTimeImmutable $when): void
    {
        $this->lastFullSyncAt = $when;
        $this->touch();
    }

    public function id(): ?int { return $this->id; }
    public function label(): string { return $this->label; }
    public function calendarId(): string { return $this->calendarId; }
    public function refreshTokenEnc(): string { return $this->refreshTokenEnc; }
    public function accessTokenEnc(): string { return $this->accessTokenEnc; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function watchChannelId(): ?string { return $this->watchChannelId; }
    public function watchResourceId(): ?string { return $this->watchResourceId; }
    public function watchTokenSecret(): ?string { return $this->watchTokenSecret; }
    public function watchExpiresAt(): ?DateTimeImmutable { return $this->watchExpiresAt; }
    public function syncToken(): ?string { return $this->syncToken; }
    public function lastFullSyncAt(): ?DateTimeImmutable { return $this->lastFullSyncAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
