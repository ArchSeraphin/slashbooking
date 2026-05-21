<?php
declare(strict_types=1);

namespace Slash\Booking\Persistence;

use Slash\Booking\Plugin;
use wpdb;

final class Migrator
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function migrate(): void
    {
        $currentVersion = (int) get_option('sb_db_version', 0);
        if ($currentVersion >= Plugin::DB_VERSION) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $this->wpdb->get_charset_collate();
        $prefix  = $this->wpdb->prefix;

        $statements = [
            "CREATE TABLE {$prefix}sb_services (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(160) NOT NULL,
                duration_min SMALLINT UNSIGNED NOT NULL,
                buffer_before_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                buffer_after_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                min_lead_time_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
                max_horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                color VARCHAR(7) NOT NULL DEFAULT '#0ea5e9',
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                settings LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_slug (slug)
            ) {$charset};",

            "CREATE TABLE {$prefix}sb_bookings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_uid CHAR(36) NOT NULL,
                service_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL,
                starts_at_utc DATETIME NOT NULL,
                ends_at_utc DATETIME NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                customer_name VARCHAR(160) NOT NULL,
                customer_email VARCHAR(200) NOT NULL,
                customer_phone VARCHAR(40) NOT NULL,
                customer_address TEXT NULL,
                customer_meta LONGTEXT NULL,
                notes TEXT NULL,
                google_event_id VARCHAR(255) NULL,
                google_event_etag VARCHAR(255) NULL,
                decision_token_hash VARCHAR(64) NULL,
                reminder_sent_at DATETIME NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_public_uid (public_uid),
                KEY idx_status_starts (status, starts_at_utc),
                KEY idx_google_event (google_event_id)
            ) {$charset};",

            "CREATE TABLE {$prefix}sb_busy_blocks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(16) NOT NULL,
                source_id VARCHAR(255) NOT NULL,
                google_account_id BIGINT UNSIGNED NULL,
                starts_at_utc DATETIME NOT NULL,
                ends_at_utc DATETIME NOT NULL,
                summary VARCHAR(255) NULL,
                last_synced_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_source (source, source_id),
                KEY idx_range (starts_at_utc, ends_at_utc)
            ) {$charset};",

            "CREATE TABLE {$prefix}sb_google_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                label VARCHAR(120) NOT NULL,
                calendar_id VARCHAR(200) NOT NULL,
                oauth_refresh_token_enc LONGTEXT NULL,
                oauth_access_token_enc LONGTEXT NULL,
                oauth_expires_at DATETIME NULL,
                watch_channel_id VARCHAR(80) NULL,
                watch_resource_id VARCHAR(255) NULL,
                watch_token_secret VARCHAR(80) NULL,
                watch_expires_at DATETIME NULL,
                sync_token VARCHAR(255) NULL,
                last_full_sync_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) {$charset};",

            "CREATE TABLE {$prefix}sb_sync_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ts DATETIME NOT NULL,
                level VARCHAR(10) NOT NULL,
                direction VARCHAR(8) NOT NULL,
                entity VARCHAR(32) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                google_event_id VARCHAR(255) NULL,
                action VARCHAR(40) NOT NULL,
                payload LONGTEXT NULL,
                status VARCHAR(16) NOT NULL,
                error_message TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_ts (ts),
                KEY idx_entity (entity, entity_id)
            ) {$charset};",

            "CREATE TABLE {$prefix}sb_mail_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_key VARCHAR(64) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                html_body LONGTEXT NOT NULL,
                text_body LONGTEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL,
                updated_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_event_key (event_key)
            ) {$charset};",
        ];

        foreach ($statements as $sql) {
            dbDelta($sql);
        }

        update_option('sb_db_version', Plugin::DB_VERSION, false);
    }
}
