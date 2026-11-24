<?php
// TEMPORARY: Operation beta diagnostics. Remove before production.
if (defined('OPERATION_BETA_DEBUG_READY')) {
    return;
}
define('OPERATION_BETA_DEBUG_READY', true);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/home/yulcari1/operation-beta-errors.log');
error_reporting(E_ALL);

$GLOBALS['operation_beta_issues'] = [];
$GLOBALS['operation_beta_exception_rendered'] = false;

function operation_beta_capture(string $type, string $message, string $file, int $line): void
{
    $issue = [
        'type' => $type,
        'message' => $message,
        'file' => basename($file),
        'line' => $line,
    ];
    $GLOBALS['operation_beta_issues'][] = $issue;
    error_log(sprintf('[Operation beta] %s: %s (%s:%d)', $type, $message, $file, $line));
}

function operation_beta_non_html(): bool
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            return stripos($header, 'text/html') === false;
        }
    }
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), [
        'process_handler.php', 'get_altitude.php',
    ], true);
}

function operation_beta_console_script(array $issues): string
{
    $json = json_encode($issues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    return '<script>console.group("[Operation BETA] PHP sistem hatalari");'
        . 'for(const issue of ' . $json . '){console.error(issue.type + ": " + issue.message + " (" + issue.file + ":" + issue.line + ")", issue);}'
        . 'console.groupEnd();</script>';
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    operation_beta_capture('PHP ' . $severity, $message, $file, $line);
    return true;
});

set_exception_handler(static function (Throwable $exception): void {
    operation_beta_capture(get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
    $GLOBALS['operation_beta_exception_rendered'] = true;
    http_response_code(500);

    if (operation_beta_non_html()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'message' => 'Internal Server Error']);
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Operation Beta</title></head><body>';
    echo operation_beta_console_script($GLOBALS['operation_beta_issues']);
    echo '</body></html>';
});

register_shutdown_function(static function (): void {
    if ($GLOBALS['operation_beta_exception_rendered']) {
        return;
    }
    $last = error_get_last();
    if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        operation_beta_capture('PHP FATAL', $last['message'], $last['file'], $last['line']);
        http_response_code(500);
    }
    if (!$GLOBALS['operation_beta_issues'] || operation_beta_non_html()) {
        return;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') === 0 || stripos($header, 'Content-Disposition: attachment') === 0) {
            return;
        }
    }
    echo operation_beta_console_script($GLOBALS['operation_beta_issues']);
});
