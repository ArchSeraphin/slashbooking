<?php
declare(strict_types=1);

namespace Trinity\Booking\Notifications;

final class TextBodyGenerator
{
    public function fromHtml(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#<a\s+[^>]*href=("|\')(.*?)\1[^>]*>(.*?)</a>#is',
            static fn (array $m): string => $m[3] . ' (' . $m[2] . ')',
            $html,
        );

        $html = (string) preg_replace('#</(p|div|h\d|li|tr)>#i', "$0\n\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this class is WP-free (testable in pure unit), wp_strip_all_tags is intentionally not used.
        $text = strip_tags($html);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);

        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $lines = array_map('trim', explode("\n", $text));
        return trim(implode("\n", $lines));
    }
}
