<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\GoogleAccount;
use wpdb;

final class GoogleAccountRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_google_accounts';
    }

    public function save(GoogleAccount $account): void
    {
        $row = $this->toRow($account);
        if ($account->id() === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->wpdb->insert($this->table, $row);
            $account->assignId((int) $this->wpdb->insert_id);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->update($this->table, $row, ['id' => $account->id()]);
    }

    public function findSingle(): ?GoogleAccount
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row("SELECT * FROM {$this->table} ORDER BY id ASC LIMIT 1", ARRAY_A);
        return is_array($row) ? GoogleAccount::fromRow($row) : null;
    }

    public function findById(int $id): ?GoogleAccount
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? GoogleAccount::fromRow($row) : null;
    }

    public function delete(int $id): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->delete($this->table, ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(GoogleAccount $a): array
    {
        $fmt = static fn (?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d H:i:s');
        return [
            'label'                    => $a->label(),
            'calendar_id'              => $a->calendarId(),
            'oauth_refresh_token_enc'  => $a->refreshTokenEnc(),
            'oauth_access_token_enc'   => $a->accessTokenEnc(),
            'oauth_expires_at'         => $fmt($a->expiresAt()),
            'watch_channel_id'         => $a->watchChannelId(),
            'watch_resource_id'        => $a->watchResourceId(),
            'watch_token_secret'       => $a->watchTokenSecret(),
            'watch_expires_at'         => $fmt($a->watchExpiresAt()),
            'sync_token'               => $a->syncToken(),
            'last_full_sync_at'        => $fmt($a->lastFullSyncAt()),
            'created_at'               => $fmt($a->createdAt()),
            'updated_at'               => $fmt($a->updatedAt()),
        ];
    }
}
