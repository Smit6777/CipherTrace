<?php
/**
 * Novalite - shared configuration and API helpers.
 * Connection settings ONLY (env overrides supported). Every business rule lives in MySQL.
 */
declare(strict_types=1);

define('DB_HOST', getenv('NOVALITE_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('NOVALITE_DB_PORT') ?: 3306));
define('DB_NAME', getenv('NOVALITE_DB_NAME') ?: 'novalite');
define('DB_USER', getenv('NOVALITE_DB_USER') ?: 'root');
define('DB_PASS', getenv('NOVALITE_DB_PASS') ?: '');

if (!class_exists('EngineException')) {
    class EngineException extends RuntimeException
    {
        public string $errorCode;

        public function __construct(string $message, string $errorCode = 'engine_error', ?Throwable $previous = null)
        {
            parent::__construct($message, 0, $previous);
            $this->errorCode = $errorCode;
        }
    }
}

class NvDbUnavailable extends RuntimeException
{
}

ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** Shared utf8mb4 connection. The real error goes to the server log only. */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        error_log('[novalite] DB connection failed: ' . $e->getMessage());
        throw new NvDbUnavailable('Database unavailable');
    }
    return $conn;
}

function json_out(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function json_error(string $code, string $message, int $status): void
{
    json_out(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

/** Allow only the listed methods. Answers OPTIONS preflight with 204. */
function require_method(string ...$allowed): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        header('Allow: ' . implode(', ', $allowed) . ', OPTIONS');
        http_response_code(204);
        exit;
    }
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('method_not_allowed', 'Use ' . implode(' or ', $allowed) . ' for this endpoint.', 405);
    }
    return $method;
}

/** Decode a JSON object body (max 1 MB). Throws validation_error. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || trim($raw) === '') {
        throw new EngineException('Request body must be a JSON object.', 'validation_error');
    }
    if (strlen($raw) > 1048576) {
        throw new EngineException('Request body is too large.', 'validation_error');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        throw new EngineException('Request body must be a JSON object.', 'validation_error');
    }
    return $data;
}

function get_setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT `value` FROM settings WHERE key_name = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row === null ? $default : (string)$row['value'];
}

/** Validated string input. */
function nv_input_string(array $in, string $key, bool $required, int $max, ?string $pattern = null): ?string
{
    if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
        if ($required) {
            throw new EngineException("'$key' is required.", 'validation_error');
        }
        return null;
    }
    if (!is_string($in[$key])) {
        throw new EngineException("'$key' must be a string.", 'validation_error');
    }
    $v = trim($in[$key]);
    if ($v === '') {
        if ($required) {
            throw new EngineException("'$key' is required.", 'validation_error');
        }
        return null;
    }
    if (mb_strlen($v) > $max) {
        throw new EngineException("'$key' must be at most $max characters.", 'validation_error');
    }
    if ($pattern !== null && preg_match($pattern, $v) !== 1) {
        throw new EngineException("'$key' has an invalid format.", 'validation_error');
    }
    return $v;
}

function nv_valid_case_id(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,20}$/', $id) === 1;
}

/** Stored status -> display label ("Approved" is shown as Approved/Auto-processed). */
function nv_status_label(string $stored): string
{
    return $stored === 'Approved' ? 'Approved/Auto-processed' : $stored;
}

/** Convert any Throwable into a safe JSON error. Never leaks SQL or stack traces. */
function nv_fail(Throwable $e): void
{
    if ($e instanceof EngineException) {
        $map = [
            'validation_error' => 400, 'invalid_sender_email' => 400, 'invalid_physical_sender' => 400,
            'on_behalf_of_required' => 400, 'invalid_case_id' => 400,
            'forbidden' => 403, 'case_not_found' => 404, 'not_found' => 404, 'conflict' => 409,
        ];
        if (isset($map[$e->errorCode])) {
            json_error($e->errorCode, $e->getMessage(), $map[$e->errorCode]);
        }
        error_log('[novalite] engine error ' . $e->errorCode . ': ' . $e->getMessage());
        json_error('server_error', 'The server could not complete the request.', 500);
    }
    if ($e instanceof NvDbUnavailable) {
        json_error('database_unavailable', 'Database unavailable', 500);
    }
    error_log('[novalite] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error('server_error', 'The server could not complete the request.', 500);
}

if (PHP_SAPI !== 'cli') {
    set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
        if (!(error_reporting() & $no)) {
            return false;
        }
        throw new ErrorException($str, 0, $no, $file, $line);
    });
    set_exception_handler(static function (Throwable $e): void {
        nv_fail($e);
    });
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[novalite] fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'The server could not complete the request.']]);
        }
    });
}
