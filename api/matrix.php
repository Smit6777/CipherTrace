<?php
/**
 * GET  api/matrix.php  - approval_matrix, document_rules, settings (the configurable business rules).
 * POST api/matrix.php  - update EXISTING approval_matrix rows. Every changed row writes one config_audit_log row.
 *
 * Blocked vendors / expired mandatory documents are decided by the engine BEFORE the matrix is consulted
 * (MR-001 / MR-002). No field here can change that, and unknown fields are rejected.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

const NV_RISK_VALUES = ['Any', 'Low', 'Medium', 'High'];
const NV_RULE_FIELDS = ['rule_id', 'min_amount', 'max_amount', 'risk_level', 'category', 'approver_email', 'approval_required'];
const NV_MAX_AMOUNT  = 9999999999.99;           // DECIMAL(12,2)

function matrix_rows(mysqli $db, bool $lock = false): array
{
    return engine_query($db,
        'SELECT rule_id, min_amount, max_amount, risk_level, category, approver_email, approval_required, description
           FROM approval_matrix ORDER BY rule_id' . ($lock ? ' FOR UPDATE' : ''));
}

function matrix_money($v, string $field, bool $nullable): ?string
{
    if ($v === null) {
        if ($nullable) {
            return null;
        }
        throw new EngineException("'$field' is required.", 'validation_error');
    }
    if (is_string($v) && preg_match('/^\d+(\.\d{1,2})?$/', trim($v)) !== 1) {
        throw new EngineException("'$field' must be a non-negative amount with at most 2 decimals.", 'validation_error');
    }
    if (!is_int($v) && !is_float($v) && !is_string($v)) {
        throw new EngineException("'$field' must be a number.", 'validation_error');
    }
    $n = (float)$v;
    if ($n < 0 || $n > NV_MAX_AMOUNT) {
        throw new EngineException("'$field' must be between 0 and " . number_format(NV_MAX_AMOUNT, 2, '.', '') . '.', 'validation_error');
    }
    return number_format($n, 2, '.', '');
}

/** Merge a patch into the current row and validate the RESULT. */
function matrix_merge(array $current, array $patch): array
{
    foreach (array_keys($patch) as $k) {
        if (!in_array($k, NV_RULE_FIELDS, true)) {
            throw new EngineException("Field '$k' cannot be changed through this endpoint.", 'validation_error');
        }
    }
    $new = [
        'rule_id' => (int)$current['rule_id'],
        'min_amount' => array_key_exists('min_amount', $patch) ? matrix_money($patch['min_amount'], 'min_amount', false) : number_format((float)$current['min_amount'], 2, '.', ''),
        'max_amount' => array_key_exists('max_amount', $patch) ? matrix_money($patch['max_amount'], 'max_amount', true)
            : ($current['max_amount'] === null ? null : number_format((float)$current['max_amount'], 2, '.', '')),
        'risk_level' => $current['risk_level'],
        'category' => $current['category'],
        'approver_email' => (string)$current['approver_email'],
        'approval_required' => (int)$current['approval_required'],
    ];
    if (array_key_exists('risk_level', $patch)) {
        if (!is_string($patch['risk_level']) || !in_array($patch['risk_level'], NV_RISK_VALUES, true)) {
            throw new EngineException("'risk_level' must be one of: " . implode(', ', NV_RISK_VALUES) . '.', 'validation_error');
        }
        $new['risk_level'] = $patch['risk_level'];
    }
    if (array_key_exists('category', $patch)) {
        $c = is_string($patch['category']) ? trim($patch['category']) : '';
        if ($c === '' || mb_strlen($c) > 60 || preg_match('/^[A-Za-z0-9][A-Za-z0-9 &\/\-]*$/', $c) !== 1) {
            throw new EngineException("'category' must be 'Any' or a name (letters, digits, space, & / -) up to 60 characters.", 'validation_error');
        }
        $new['category'] = $c;
    }
    if (array_key_exists('approval_required', $patch)) {
        if ($patch['approval_required'] !== 0 && $patch['approval_required'] !== 1) {
            throw new EngineException("'approval_required' must be 0 or 1.", 'validation_error');
        }
        $new['approval_required'] = (int)$patch['approval_required'];
    }
    if (array_key_exists('approver_email', $patch)) {
        if (!is_string($patch['approver_email']) || mb_strlen(trim($patch['approver_email'])) > 500) {
            throw new EngineException("'approver_email' must be a string of at most 500 characters.", 'validation_error');
        }
        $new['approver_email'] = trim($patch['approver_email']);
    }

    if ($new['max_amount'] !== null && (float)$new['max_amount'] < (float)$new['min_amount']) {
        throw new EngineException("'max_amount' must be null or >= 'min_amount'.", 'validation_error');
    }
    $emails = engine_csv($new['approver_email']);
    if ($new['approval_required'] === 1 && !$emails) {
        throw new EngineException("'approver_email' is required when approval_required = 1.", 'validation_error');
    }
    foreach ($emails as $e) {
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new EngineException("'approver_email' contains an invalid address: $e", 'validation_error');
        }
    }
    $new['approver_email'] = implode(',', $emails);
    return $new;
}

/** True when the rules that apply to ANY risk level cover 0 .. no-upper-limit with no gap > 0.01. */
function matrix_covers_all_amounts(array $rows): bool
{
    $bands = [];
    foreach ($rows as $r) {
        if ($r['risk_level'] === 'Any') {
            $bands[] = [(float)$r['min_amount'], $r['max_amount'] === null ? INF : (float)$r['max_amount']];
        }
    }
    if (!$bands) {
        return false;
    }
    usort($bands, static fn($a, $b) => $a[0] <=> $b[0]);
    if ($bands[0][0] > 0.0) {
        return false;
    }
    $reach = -INF;
    foreach ($bands as [$min, $max]) {
        if ($reach !== -INF && $min > $reach + 0.011) {
            return false;
        }
        $reach = max($reach, $max);
    }
    return $reach === INF;
}

try {
    $method = require_method('GET', 'POST');
    $db = db();

    if ($method === 'GET') {
        json_out([
            'approval_matrix' => matrix_rows($db),
            'document_rules' => engine_query($db,
                'SELECT document_type, is_mandatory, active, scope, sort_order FROM document_rules ORDER BY sort_order, document_type'),
            'settings' => engine_query($db, 'SELECT key_name, `value` FROM settings ORDER BY key_name'),
            'non_overridable' => [
                'Vendors whose status is in settings.hard_stop_vendor_statuses (Blocked) go to Manual Review before the matrix is consulted (MR-001).',
                'Expired mandatory documents go to Manual Review before the matrix is consulted (MR-002).',
            ],
        ], 200);
    }

    // ---------------- POST ----------------
    $in = read_json_body();
    foreach (array_keys($in) as $k) {
        if (!in_array($k, ['rules', 'changed_by'], true)) {
            throw new EngineException("Unknown field '$k'. Send {\"rules\":[...],\"changed_by\":\"...\"}.", 'validation_error');
        }
    }
    if (!isset($in['rules']) || !is_array($in['rules']) || !$in['rules'] || !array_is_list($in['rules']) || count($in['rules']) > 50) {
        throw new EngineException("'rules' must be a non-empty list (max 50) of rule objects.", 'validation_error');
    }
    $changedBy = nv_input_string($in, 'changed_by', false, 190) ?? 'api-unauthenticated';

    $patches = [];
    foreach ($in['rules'] as $p) {
        if (!is_array($p) || array_is_list($p) || !isset($p['rule_id']) || !is_int($p['rule_id']) || $p['rule_id'] < 1) {
            throw new EngineException("Each rule must be an object with an integer 'rule_id'.", 'validation_error');
        }
        if (isset($patches[$p['rule_id']])) {
            throw new EngineException("rule_id {$p['rule_id']} appears twice.", 'validation_error');
        }
        $patches[$p['rule_id']] = $p;
    }

    $updated = [];
    $unchanged = [];
    $db->begin_transaction();
    try {
        $before = matrix_rows($db, true);
        $byId = [];
        foreach ($before as $r) {
            $byId[(int)$r['rule_id']] = $r;
        }
        foreach ($patches as $id => $patch) {
            if (!isset($byId[$id])) {
                throw new EngineException("Approval rule $id does not exist.", 'not_found');
            }
            $new = matrix_merge($byId[$id], $patch);
            $old = [
                'rule_id' => $id, 'min_amount' => number_format((float)$byId[$id]['min_amount'], 2, '.', ''),
                'max_amount' => $byId[$id]['max_amount'] === null ? null : number_format((float)$byId[$id]['max_amount'], 2, '.', ''),
                'risk_level' => $byId[$id]['risk_level'], 'category' => $byId[$id]['category'],
                'approver_email' => implode(',', engine_csv((string)$byId[$id]['approver_email'])),
                'approval_required' => (int)$byId[$id]['approval_required'],
            ];
            if ($old == $new) {
                $unchanged[] = $id;
                continue;
            }
            engine_exec($db,
                'UPDATE approval_matrix SET min_amount = ?, max_amount = ?, risk_level = ?, category = ?, approver_email = ?, approval_required = ?
                  WHERE rule_id = ?',
                'sssssii', [$new['min_amount'], $new['max_amount'], $new['risk_level'], $new['category'], $new['approver_email'], $new['approval_required'], $id]);
            engine_exec($db,
                'INSERT INTO config_audit_log (table_name, record_key, changed_by, old_value, new_value) VALUES (?,?,?,?,?)',
                'sssss', ['approval_matrix', (string)$id, $changedBy,
                    json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $updated[] = $id;
        }
        $after = matrix_rows($db);
        if (matrix_covers_all_amounts($before) && !matrix_covers_all_amounts($after)) {
            throw new EngineException('This change would leave amounts with no approval rule (gap or missing open-ended top band).', 'conflict');
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    json_out(['updated' => $updated, 'unchanged' => $unchanged, 'approval_matrix' => matrix_rows($db)], 200);
} catch (Throwable $e) {
    nv_fail($e);
}
