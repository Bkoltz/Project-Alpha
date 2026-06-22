<?php
/**
 * Central output-escaping helper for Project Alpha views.
 *
 * This helper wraps htmlspecialchars with secure defaults so views do not need
 * to remember the full flag/encoding argument list. Use it for any untrusted
 * data emitted into HTML (including GET/POST/session values).
 *
 * Example:
 *   <input value="<?php echo e($foo); ?>">
 *
 * @param mixed  $text   Value to escape. Non-scalars are cast to string.
 * @param string $encoding Character encoding (default: UTF-8).
 * @return string Escaped HTML-safe string.
 */
function e(mixed $text, string $encoding = 'UTF-8'): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, $encoding);
}

/**
 * Alias for `e()`. Use whichever reads better in a given context.
 *
 * @param mixed  $text   Value to escape.
 * @param string $encoding Character encoding (default: UTF-8).
 * @return string Escaped HTML-safe string.
 */
function html(mixed $text, string $encoding = 'UTF-8'): string
{
    return e($text, $encoding);
}
