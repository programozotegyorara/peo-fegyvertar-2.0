<?php

declare(strict_types=1);

namespace Peoft\Integrations\Mailer;

defined('ABSPATH') || exit;

/**
 * Renders an EmailTemplate to `{subject, html}` by substituting placeholders.
 *
 * Two placeholder forms:
 *   {{name}}   — HTML-escaped substitution (safe default; protects against
 *                stored XSS if a $var came from user input like customer_name)
 *   {{{name}}} — raw substitution (trusted HTML like invoice links with href attrs)
 *
 * Strict validation: every name listed in $template->declared MUST be present
 * in $vars (even if empty string is an acceptable value). Missing names →
 * TemplateVariableMissingException (a PoisonException subclass).
 *
 * Extra keys in $vars beyond $template->declared are silently ignored. This
 * is intentional so handlers can pass a superset without caring which
 * specific template is being rendered.
 */
final class TemplateRenderer
{
    public function __construct(
        private readonly TemplateRepository $templates,
    ) {}

    /**
     * @param array<string,mixed> $vars
     * @return array{subject:string, html:string}
     */
    public function render(string $slug, array $vars): array
    {
        $template = $this->templates->mustFind($slug);

        $missing = [];
        foreach ($template->declared as $name) {
            if (!array_key_exists($name, $vars)) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new TemplateVariableMissingException($slug, $missing);
        }

        return [
            'subject' => $this->substitute($template->subject, $vars),
            'html'    => $this->substitute($template->body, $vars),
        ];
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function substitute(string $source, array $vars): string
    {
        // Triple-brace first (raw), then double-brace (escaped), so that
        // `{{{foo}}}` isn't eaten by the outer `{{foo}}` regex.
        $source = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                if (!array_key_exists($key, $vars)) {
                    return $m[0]; // leave untouched — undeclared extras
                }
                return self::stringify($vars[$key]);
            },
            $source
        ) ?? $source;

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                if (!array_key_exists($key, $vars)) {
                    return $m[0]; // leave untouched
                }
                return htmlspecialchars(self::stringify($vars[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $source
        ) ?? $source;
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? '' : $json;
        }
        return '';
    }
}
