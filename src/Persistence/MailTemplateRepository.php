<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Notifications\DefaultTemplates;
use Trinity\Booking\Notifications\Events\EventKey;
use wpdb;

/**
 * @phpstan-type Template array{
 *   subject:string,
 *   html_body:string,
 *   text_body:?string,
 *   enabled:bool,
 *   is_custom:bool,
 *   updated_at:?string,
 *   updated_by:?int,
 * }
 */
final class MailTemplateRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_mail_templates';
    }

    /**
     * @return Template
     */
    public function getOrDefault(EventKey $event): array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE event_key = %s",
                $event->value,
            ),
            ARRAY_A
        );

        if (is_array($row) && (int) $row['enabled'] === 1) {
            return [
                'subject'    => (string) $row['subject'],
                'html_body'  => (string) $row['html_body'],
                'text_body'  => $row['text_body'] !== null ? (string) $row['text_body'] : null,
                'enabled'    => true,
                'is_custom'  => true,
                'updated_at' => (string) $row['updated_at'],
                'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            ];
        }

        $defaults = DefaultTemplates::all();
        $default = $defaults[$event->value];
        return [
            'subject'    => $default['subject'],
            'html_body'  => $default['html_body'],
            'text_body'  => null,
            'enabled'    => true,
            'is_custom'  => false,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    public function save(
        EventKey $event,
        string $subject,
        string $htmlBody,
        ?string $textBody,
        bool $enabled,
        int $updatedBy,
    ): void {
        $now = current_time('mysql', true);
        $data = [
            'event_key'  => $event->value,
            'subject'    => $subject,
            'html_body'  => $htmlBody,
            'text_body'  => $textBody,
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => $now,
            'updated_by' => $updatedBy,
        ];
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT id FROM {$this->table} WHERE event_key = %s", $event->value)
        );
        if ($existing !== null) {
            $this->wpdb->update($this->table, $data, ['event_key' => $event->value]);
            return;
        }
        $this->wpdb->insert($this->table, $data);
    }

    public function delete(EventKey $event): void
    {
        $this->wpdb->delete($this->table, ['event_key' => $event->value]);
    }
}
