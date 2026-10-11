<?php
/**
 * POST api/intake.php - receive one email, identify the real sender, classify, create ONE case, decide it.
 * Case creation + decision (case update, ONE audit_log row, ONE mail_log row) are a single transaction.
 * Duplicate thread_id => 200 with the existing case (idempotent); no second case, no second audit row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/parse.php';
require_once __DIR__ . '/../engine/classify.php';
require_once __DIR__ . '/../engine/decide.php';

/** Existing-case response (idempotent path). */
function intake_existing_response(mysqli $db, string $threadId): ?array
{
    $c = engine_query_one($db,
        'SELECT case_id, real_sender_email, request_type, current_status, next_action, owner
           FROM workflow_cases WHERE thread_id = ?', 's', [$threadId]);
    if ($c === null) {
        return null;
    }
    return [
        'case_id' => $c['case_id'],
        'real_sender' => ['name' => '', 'email' => $c['real_sender_email'], 'source' => 'stored'],
        'request_type' => $c['request_type'],
        'status' => nv_status_label((string)$c['current_status']),
        'next_action' => $c['next_action'],
        'owner' => $c['owner'],
        'duplicate' => true,
    ];
}

/** Allocate VP-1001 style id; MUST be called inside a transaction (row lock on the counter). */
function intake_allocate_case_id(mysqli $db): string
{
    $prefix = engine_require_setting($db, 'case_prefix');
    if (preg_match('/^[A-Za-z0-9]{1,8}$/', $prefix) !== 1) {
        throw new EngineException("Setting 'case_prefix' must be 1-8 letters/digits.", 'config_error');
    }
    $row = engine_query_one($db, 'SELECT `value` FROM settings WHERE key_name = ? FOR UPDATE', 's', ['case_next_number']);
    if ($row === null || !ctype_digit(trim((string)$row['value']))) {
        throw new EngineException("Setting 'case_next_number' must be a whole number.", 'config_error');
    }
    $n = (int)trim((string)$row['value']);
    for ($i = 0; $i < 100; $i++, $n++) {
        $id = $prefix . '-' . $n;
        if (strlen($id) > 20) {
            throw new EngineException('Generated case id exceeds 20 characters.', 'config_error');
        }
        if (engine_query_one($db, 'SELECT 1 AS x FROM workflow_cases WHERE case_id = ?', 's', [$id]) === null) {
            engine_exec($db, 'UPDATE settings SET `value` = ? WHERE key_name = ?', 'ss', [(string)($n + 1), 'case_next_number']);
            return $id;
        }
    }
    throw new EngineException('Could not allocate a unique case id.', 'config_error');
}

try {
    require_method('POST');
    $in = read_json_body();

    $threadId = nv_input_string($in, 'thread_id', true, 100, '/^[A-Za-z0-9._:@\-]+$/');
    $from     = strtolower((string)nv_input_string($in, 'from', true, 190));
    $subject  = (string)nv_input_string($in, 'subject', false, 255);
    $body     = $in['body'] ?? null;
    if (!is_string($body) || trim($body) === '') {
        throw new EngineException("'body' is required.", 'validation_error');
    }
    if (mb_strlen($body) > 100000) {
        throw new EngineException("'body' must be at most 100000 characters.", 'validation_error');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new EngineException("'from' must be a valid email address.", 'validation_error');
    }

    $db = db();

    // Idempotent fast path (no parsing, no writes).
    $existing = intake_existing_response($db, $threadId);
    if ($existing !== null) {
        json_out($existing, 200);
    }

    $real = extract_real_sender($db, $body, $from);          // may throw on_behalf_of_required
    $cls  = classify_request($subject, $body);
    $ex   = $cls['extracted'];
    $context = [
        'request_id' => $ex['request_id'], 'amount' => $ex['amount'],
        'category' => $ex['category'], 'confidence' => $cls['confidence'],
    ];

    $db->begin_transaction();
    try {
        $caseId = intake_allocate_case_id($db);
        $owner  = engine_setting($db, 'system_from_email', '') ?: '';
        engine_exec($db,
            'INSERT INTO workflow_cases (case_id, thread_id, real_sender_email, request_type, current_status, next_action, owner)
             VALUES (?,?,?,?,?,?,?)',
            'sssssss', [$caseId, $threadId, $real['email'], $cls['request_type'], 'New', 'Pending evaluation', $owner]);
        $result = decide_case($db, $caseId, $context, true);   // same transaction: ONE audit row, ONE mail row
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {   // concurrent duplicate thread_id
            $dup = intake_existing_response($db, $threadId);
            if ($dup !== null) {
                json_out($dup, 200);
            }
        }
        throw $e;
    }

    json_out([
        'case_id' => $caseId,
        'real_sender' => ['name' => $real['name'], 'email' => $real['email'], 'source' => $real['source']],
        'request_type' => $cls['request_type'],
        'status' => nv_status_label((string)$result['status']),
        'next_action' => $result['next_action'],
        'owner' => $result['owner'],
        'duplicate' => false,
    ], 201);
} catch (Throwable $e) {
    nv_fail($e);
}
