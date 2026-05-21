<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

final class TemplateRenderer
{
    public function __construct(private readonly TagRegistry $tags)
    {
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function render(string $template, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-z_][a-z0-9_]*)\}\}/i',
            function (array $m) use ($data): string {
                $name = $m[1];
                $tag  = $this->tags->find($name);
                if ($tag === null) {
                    return $m[0];
                }
                $value = (string) ($data[$name] ?? '');
                if ($tag['raw']) {
                    return $value;
                }
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $template,
        );
    }
}
