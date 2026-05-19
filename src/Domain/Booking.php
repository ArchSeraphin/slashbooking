<?php

declare(strict_types=1);

namespace Trinity\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class Booking
{
    /**
     * @param array<string, mixed> $customerMeta
     */
    private function __construct(
        private ?int $id,
        private readonly string $publicUid,
        private readonly int $serviceId,
        private BookingStatus $status,
        private readonly TimeSlot $slot,
        private readonly string $timezone,
        private readonly string $customerName,
        private readonly string $customerEmail,
        private readonly string $customerPhone,
        private readonly string $customerAddress,
        private readonly array $customerMeta,
        private readonly string $notes,
        private ?string $googleEventId,
        private ?string $googleEventEtag,
        private ?string $decisionTokenHash,
        private ?DateTimeImmutable $reminderSentAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $customerMeta
     */
    public static function createPending(
        int $serviceId,
        TimeSlot $slot,
        string $timezone,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        string $customerAddress,
        array $customerMeta,
        string $notes,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return new self(
            id: null,
            publicUid: self::generateUuid(),
            serviceId: $serviceId,
            status: BookingStatus::PENDING,
            slot: $slot,
            timezone: $timezone,
            customerName: $customerName,
            customerEmail: $customerEmail,
            customerPhone: $customerPhone,
            customerAddress: $customerAddress,
            customerMeta: $customerMeta,
            notes: $notes,
            googleEventId: null,
            googleEventEtag: null,
            decisionTokenHash: null,
            reminderSentAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function confirm(): void
    {
        $this->mustBe(BookingStatus::PENDING, 'confirm');
        $this->status = BookingStatus::CONFIRMED;
        $this->touch();
    }

    public function reject(): void
    {
        $this->mustBe(BookingStatus::PENDING, 'reject');
        $this->status = BookingStatus::REJECTED;
        $this->touch();
    }

    public function cancel(): void
    {
        if (!in_array($this->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)) {
            throw new DomainException("Cannot cancel from status {$this->status->value}");
        }
        $this->status = BookingStatus::CANCELLED;
        $this->touch();
    }

    public function markReminderSent(DateTimeImmutable $at): void
    {
        if ($this->reminderSentAt !== null) {
            throw new DomainException('Reminder already sent.');
        }
        $this->reminderSentAt = $at;
        $this->touch();
    }

    public function setGoogleEvent(string $eventId, string $etag): void
    {
        $this->googleEventId = $eventId;
        $this->googleEventEtag = $etag;
        $this->touch();
    }

    public function setDecisionTokenHash(string $hash): void
    {
        $this->decisionTokenHash = $hash;
        $this->touch();
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new DomainException('Booking id already assigned.');
        }
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function publicUid(): string
    {
        return $this->publicUid;
    }

    public function serviceId(): int
    {
        return $this->serviceId;
    }

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function slot(): TimeSlot
    {
        return $this->slot;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerEmail(): string
    {
        return $this->customerEmail;
    }

    public function customerPhone(): string
    {
        return $this->customerPhone;
    }

    public function customerAddress(): string
    {
        return $this->customerAddress;
    }

    /**
     * @return array<string, mixed>
     */
    public function customerMeta(): array
    {
        return $this->customerMeta;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function googleEventId(): ?string
    {
        return $this->googleEventId;
    }

    public function googleEventEtag(): ?string
    {
        return $this->googleEventEtag;
    }

    public function decisionTokenHash(): ?string
    {
        return $this->decisionTokenHash;
    }

    public function reminderSentAt(): ?DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function mustBe(BookingStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw new DomainException("Cannot {$action} from status {$this->status->value}");
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
