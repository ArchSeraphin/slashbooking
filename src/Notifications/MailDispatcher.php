<?php
declare(strict_types=1);

namespace Trinity\Booking\Notifications;

use Throwable;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Notifications\Events\BookingContext;
use Trinity\Booking\Notifications\Events\EventKey;
use Trinity\Booking\Persistence\MailTemplateRepository;

final class MailDispatcher
{
    public function __construct(
        private readonly MailTemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly TextBodyGenerator $text,
        private readonly IcsBuilder $ics,
    ) {
    }

    public function send(
        EventKey $event,
        string $recipient,
        BookingContext $context,
        ?Booking $withIcsFor = null,
    ): bool {
        try {
            $tpl  = $this->templates->getOrDefault($event);
            $data = $context->toArray();

            $subject  = $this->renderer->render($tpl['subject'], $data);
            $html     = $this->renderer->render($tpl['html_body'], $data);
            $textBody = $tpl['text_body'] !== null && $tpl['text_body'] !== ''
                ? $this->renderer->render($tpl['text_body'], $data)
                : $this->text->fromHtml($html);

            $boundary = 'tb-' . bin2hex(random_bytes(8));
            $headers = [
                'From: ' . $this->fromHeader(),
                'Reply-To: ' . $this->replyTo($recipient, $context),
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $textBody . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $html . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $attachments = [];
            if ($withIcsFor !== null) {
                $attachments[] = $this->writeIcsTempFile($withIcsFor, $subject);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- wp_mail is the WP-recommended mail function; used correctly here.
            $sent = wp_mail($recipient, $subject, $body, $headers, $attachments);

            foreach ($attachments as $path) {
                if (file_exists($path)) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- intentional: temp ICS cleanup after send; error is non-critical; wp_delete_file() wraps unlink but is not available in all contexts and adds no value here.
                    @unlink($path);
                }
            }

            if (!$sent) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: mail dispatch failure must be logged; no WP equivalent for server-side error logging.
                error_log(sprintf('[trinity-booking] wp_mail failed for event=%s to=%s', $event->value, $recipient));
            }
            return (bool) $sent;
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: fail-safe catch block; error_log is the only safe logging channel available without WP context.
            error_log('[trinity-booking] MailDispatcher exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a raw HTML e-mail without templating. Used for "Send test" from
     * the templates editor admin SPA.
     *
     * Returns true if wp_mail() reports success.
     */
    public function sendRaw(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromHeader(),
        ];

        return (bool) wp_mail($to, $subject, $htmlBody, $headers);
    }

    private function fromHeader(): string
    {
        $name  = (string) get_option('blogname', 'WordPress');
        $email = (string) get_option('admin_email', 'no-reply@example.com');
        return sprintf('%s <%s>', $name, $email);
    }

    private function replyTo(string $recipient, BookingContext $ctx): string
    {
        $admin = (string) ($ctx->toArray()['admin_email'] ?? get_option('admin_email', ''));
        return $admin !== '' ? $admin : $recipient;
    }

    private function writeIcsTempFile(Booking $b, string $summary): string
    {
        $ics = $this->ics->build(
            uid: $b->publicUid() . '@trinity-booking',
            summary: $summary,
            description: '',
            startUtc: $b->slot()->start,
            endUtc:   $b->slot()->end,
        );
        $dir  = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $path = rtrim($dir, '/\\') . '/' . 'tb-' . $b->publicUid() . '.ics';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp ICS file for transient email attachment; WP_Filesystem is not available in mail dispatch context and not appropriate for ephemeral temp files.
        file_put_contents($path, $ics);
        return $path;
    }
}
