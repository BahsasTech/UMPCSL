<?php
declare(strict_types=1);

/**
 * Small collection of shared helper functions.
 */

/** Read a config value using dot notation, e.g. config('db.host'). */
function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config/config.php';
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

/** Escape a value for safe HTML output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as currency using the configured symbol. */
function money($amount, bool $symbol = true): string
{
    $formatted = number_format((float) $amount, 2);
    return $symbol ? config('app.currency') . $formatted : $formatted;
}

/** Format a date/time value, returning a dash when empty. */
function fmt_date(?string $value, string $format = 'd M Y'): string
{
    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

/** Human readable label for an enum slug. */
function label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

/** Issue an HTTP redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Store a one-time flash message. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Retrieve and clear all flash messages. */
function flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/** Get (creating if necessary) the CSRF token for this session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input containing the CSRF token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate a submitted CSRF token. */
function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/** Abort with a 403 response when the CSRF token is missing or wrong. */
function require_csrf(): void
{
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired security token. Please go back and try again.');
    }
}

/** Read a request value from POST. */
function post(string $key, $default = null)
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Read a request value from GET. */
function get(string $key, $default = null)
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

/** Generate a unique reference such as UMP-MBR-000123. */
function next_reference(string $table, string $column, string $prefix, int $pad = 6): string
{
    $base = 'SELECT COALESCE(MAX(id), 0) + 1 FROM ' . $table;
    $next = (int) fetch_value($base);
    do {
        $ref = $prefix . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
        $exists = (int) fetch_value("SELECT COUNT(*) FROM $table WHERE $column = ?", [$ref]);
        $next++;
    } while ($exists > 0);
    return $ref;
}

/** Turn a decimal string into a float safely. */
function to_amount($value): float
{
    return round((float) str_replace(',', '', (string) $value), 2);
}
