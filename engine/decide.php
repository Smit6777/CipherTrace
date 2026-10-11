<?php
/**
 * engine/decide.php - decision engine.
 * Every decide_case() call = ONE transaction containing: case update, exactly ONE audit_log row,
 * exactly ONE mail_log row. All business values come from MySQL. Returns arrays / throws EngineException.
 */
declare(strict_types=1);

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

/* Status vocabulary stored in workflow_cases.current_status / procurement_requests.status */
const NV_STATUS_APPROVED         = 'Approved';            // displayed as "Approved/Auto-processed"
const NV_STATUS_PENDING_DOCS     = 'Pending Documents';
const NV_STATUS_PENDING_APPROVAL = 'Pending Approval';
const NV_STATUS_MANUAL_REVIEW    = 'Manual Review';
const NV_STATUS_REJECTED         = 'Rejected';          // PART 3 PATCH: set by api/approve.php on REJECT
const NV_ACTOR                   = 'Novalite';
const NV_RULE_DOCS               = 'DOC-001';

/* ============================== DB helpers (prepared only) ============================== */

function engine_query(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

function engine_query_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $rows = engine_query($db, $sql, $types, $params);
    return $rows[0] ?? null;
}

/** INSERT/UPDATE; returns [insert_id, affected]. */
function engine_exec(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $st = $db->prepare($sql);
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
    $st->execute();
    $out = [(int)$st->insert_id, (int)$st->affected_rows];
    $st->close();
    return $out;
}

function engine_setting(mysqli $db, string $key, ?string $default = null): ?string
{
    $r = engine_query_one($db, 'SELECT `value` FROM settings WHERE key_name = ?', 's', [$key]);
    return $r === null ? $default : (string)$r['value'];
}

function engine_require_setting(mysqli $db, string $key): string
{
    $v = engine_setting($db, $key);
    if ($v === null || trim($v) === '') {
        throw new EngineException("Required setting '$key' is not configured.", 'config_error');
    }
    return trim($v);
}

function engine_csv(?string $v): array
{
    if ($v === null) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
}

/** settings.business_date (YYYY-MM-DD) or, if absent/invalid, the database's current date. */
function engine_business_date(mysqli $db): string
{
    $v = engine_setting($db, 'business_date');
    if ($v !== null) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
        if ($d !== false && $d->format('Y-m-d') === trim($v)) {
            return trim($v);
        }
    }
    return (string)engine_query_one($db, 'SELECT CURDATE() AS d')['d'];
}

function nv_money($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

/* ============================== lookups ============================== */

function find_vendor_by_email(mysqli $db, string $email): ?array
{
    return engine_query_one($db,
        'SELECT vendor_id, vendor_name, vendor_email, vendor_status, risk_level, gst_id, bank_verification_status
           FROM vendors WHERE vendor_email = ? LIMIT 1', 's', [strtolower(trim($email))]);
}

function nv_document_status(?string $verification, ?string $expiry, string $businessDate): string
{
    if ($verification === null || $verification === 'Missing') {
        return 'Missing';
    }
    if ($verification === 'Expired') {
        return 'Expired';
    }
    if ($expiry !== null && $expiry < $businessDate) {
        return 'Expired';
    }
    if ($verification === 'Verified') {
        return 'Valid';
    }
    return 'Pending';
}

/**
 * Check every active mandatory document_rules entry against the vendor's documents.
 * @return array{checks:array,missing:array,expired:array,pending:array,valid:array}
 */
function get_mandatory_document_issues(mysqli $db, int $vendorId, string $vendorStatus, string $businessDate): array
{
    $rows = engine_query($db,
        "SELECT dr.document_type, vd.verification_status, vd.expiry_date
           FROM document_rules dr
           LEFT JOIN vendor_documents vd ON vd.document_type = dr.document_type AND vd.vendor_id = ?
          WHERE dr.is_mandatory = 1 AND dr.active = 1 AND (dr.scope = 'ALL_VENDORS' OR dr.scope = ?)
          ORDER BY dr.sort_order, dr.document_type",
        'is', [$vendorId, $vendorStatus]);
    if (!$rows) {
        throw new EngineException('No active mandatory document rules are configured.', 'config_error');
    }
    $out = ['checks' => [], 'missing' => [], 'expired' => [], 'pending' => [], 'valid' => []];
    foreach ($rows as $r) {
        $s = nv_document_status($r['verification_status'], $r['expiry_date'], $businessDate);
        $out['checks'][] = [
            'document_type' => $r['document_type'], 'status' => $s,
            'verification_status' => $r['verification_status'], 'expiry_date' => $r['expiry_date'],
        ];
        $out[strtolower($s)][] = $r['document_type'];
    }
    return $out;
}

/**
 * Safe, deterministic request matching. Never invents a match.
 * @return array{matched:bool,request:?array,method:string,note:string}
 */
function find_matching_request(mysqli $db, array $case, ?array $vendor, array $context): array
{
    $out = ['matched' => false, 'request' => null, 'method' => 'none', 'note' => ''];
    $ctxAmount = (isset($context['amount']) && is_numeric($context['amount']) && (float)$context['amount'] > 0)
        ? (float)$context['amount'] : null;
    $ctxCategory = (isset($context['category']) && is_string($context['category']) && trim($context['category']) !== '')
        ? trim($context['category']) : null;

    $requestId = null;
    $method = 'none';
    if ($case['linked_request_id'] !== null) {
        $requestId = (int)$case['linked_request_id'];
        $method = 'linked_request_id';
    } elseif (isset($context['request_id']) && is_numeric($context['request_id']) && (int)$context['request_id'] > 0) {
        $requestId = (int)$context['request_id'];
        $method = 'request_reference_in_message';
    }

    $cols = 'request_id, requester_email, vendor_id, category, amount, status, required_by_date';

    if ($requestId !== null) {
        $out['method'] = $method;
        $req = engine_query_one($db, "SELECT $cols FROM procurement_requests WHERE request_id = ?", 'i', [$requestId]);
        if ($req === null) {
            $out['note'] = "Referenced request #$requestId does not exist.";
            return $out;
        }
        if ($vendor === null) {
            $out['note'] = "Request #$requestId cannot be linked because the vendor is unknown.";
            return $out;
        }
        if ($req['vendor_id'] === null || (int)$req['vendor_id'] !== (int)$vendor['vendor_id']) {
            $out['note'] = "Request #$requestId belongs to a different vendor (or none).";
            return $out;
        }
        if ($ctxAmount !== null && abs((float)$req['amount'] - $ctxAmount) > 0.005) {
            $out['note'] = 'Amount in the message (' . nv_money($ctxAmount) . ') differs from request #' . $requestId . ' (' . nv_money($req['amount']) . ').';
            return $out;
        }
        $out['matched'] = true;
        $out['request'] = $req;
        $out['note'] = "Request #$requestId linked to this vendor.";
        return $out;
    }

    if ($vendor === null || $case['request_type'] !== 'procurement_request') {
        $out['note'] = 'No request reference available.';
        return $out;
    }

    $open = engine_csv(engine_setting($db, 'open_request_statuses', 'New'));
    if (!$open) {
        $out['note'] = 'No open request statuses configured.';
        return $out;
    }
    $ph = implode(',', array_fill(0, count($open), '?'));
    $cands = engine_query($db,
        "SELECT $cols FROM procurement_requests WHERE vendor_id = ? AND status IN ($ph) ORDER BY request_id",
        'i' . str_repeat('s', count($open)), array_merge([(int)$vendor['vendor_id']], $open));
    $cands = array_values(array_filter($cands, static function (array $r) use ($ctxAmount, $ctxCategory): bool {
        if ($ctxAmount !== null && abs((float)$r['amount'] - $ctxAmount) > 0.005) {
            return false;
        }
        if ($ctxCategory !== null && strcasecmp($r['category'], $ctxCategory) !== 0) {
            return false;
        }
        return true;
    }));
    $out['method'] = 'vendor_open_request';
    if (count($cands) === 1) {
        $out['matched'] = true;
        $out['request'] = $cands[0];
        $out['note'] = 'Exactly one open request for this vendor fits the message.';
    } elseif (count($cands) === 0) {
        $out['note'] = 'No open request for this vendor fits the message.';
    } else {
        $out['note'] = count($cands) . ' open requests fit; cannot choose safely.';
    }
    return $out;
}

/** Most specific compatible matrix row (risk match beats Any, category match beats Any, narrower band wins). */
function find_approval_rule(mysqli $db, string $amount, string $riskLevel, ?string $category): ?array
{
    return engine_query_one($db,
        "SELECT rule_id, min_amount, max_amount, risk_level, category, approver_email, approval_required
           FROM approval_matrix
          WHERE min_amount <= CAST(? AS DECIMAL(12,2))
            AND (max_amount IS NULL OR CAST(? AS DECIMAL(12,2)) <= max_amount)
            AND (risk_level = 'Any' OR risk_level = ?)
            AND (category = 'Any' OR (? IS NOT NULL AND LOWER(category) = LOWER(?)))
          ORDER BY (risk_level <> 'Any') DESC, (category <> 'Any') DESC,
                   (max_amount IS NULL) ASC, (max_amount - min_amount) ASC, rule_id ASC
          LIMIT 1",
        'sssss', [$amount, $amount, $riskLevel, $category, $category]);
}

/* ============================== writers ============================== */

function insert_audit_log(mysqli $db, string $caseId, string $action, string $reason, string $ruleId, array $evidence, string $actor = NV_ACTOR): int
{
    $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    [$id] = engine_exec($db,
        'INSERT INTO audit_log (case_id, action, decision_reason, rule_id, actor, evidence) VALUES (?,?,?,?,?,?)',
        'ssssss', [$caseId, $action, $reason, $ruleId, $actor, $json]);
    return $id;
}

function insert_mail_log(mysqli $db, string $caseId, string $recipient, string $subject, string $body, string $type): int
{
    [$id] = engine_exec($db,
        'INSERT INTO mail_log (case_id, recipient_email, subject, body, mail_type) VALUES (?,?,?,?,?)',
        'sssss', [$caseId, mb_substr($recipient, 0, 190), mb_substr($subject, 0, 255), $body, $type]);
    return $id;
}

function update_case_state(mysqli $db, string $caseId, string $status, string $nextAction, string $owner, ?int $vendorId, ?int $requestId, ?string $followUpDue = null): void
{
    // PART 3 PATCH: follow_up_due_at (structured DATE) and chase_count are persisted with the decision.
    engine_exec($db,
        'UPDATE workflow_cases
            SET current_status = ?, next_action = ?, owner = ?,
                follow_up_due_at = ?, chase_count = 0,
                linked_vendor_id = COALESCE(?, linked_vendor_id),
                linked_request_id = COALESCE(?, linked_request_id)
          WHERE case_id = ?',
        'ssssiis', [$status, mb_substr($nextAction, 0, 500), mb_substr($owner, 0, 190), $followUpDue, $vendorId, $requestId, $caseId]);
}

/* ============================== decision engine ============================== */

/**
 * Decide one case. $context (optional, from classify_request): request_id, amount, category, confidence.
 * @return array decision summary (status, rule_id, owner, next_action, audit_id, mail_id, evidence ...)
 * @throws EngineException|Throwable  (transaction rolled back)
 */
function decide_case(mysqli $db, string $caseId, array $context = [], bool $inTransaction = false): array
{
    $caseId = trim($caseId);
    if ($caseId === '' || strlen($caseId) > 20) {
        throw new EngineException('Invalid case id.', 'invalid_case_id');
    }
    if ($inTransaction) {   // PART 3 PATCH: caller owns the transaction (intake / cron re-evaluation)
        return nv_decide_in_tx($db, $caseId, $context);
    }
    $db->begin_transaction();
    try {
        $result = nv_decide_in_tx($db, $caseId, $context);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function nv_decide_in_tx(mysqli $db, string $caseId, array $context): array
{
    $case = engine_query_one($db,
        'SELECT case_id, thread_id, real_sender_email, request_type, linked_vendor_id, linked_request_id,
                current_status, next_action, owner, created_at
           FROM workflow_cases WHERE case_id = ? FOR UPDATE', 's', [$caseId]);
    if ($case === null) {
        throw new EngineException("Case $caseId was not found.", 'case_not_found');
    }

    $businessDate = engine_business_date($db);
    $reviewOwner  = engine_require_setting($db, 'manual_review_owner');
    $systemOwner  = engine_setting($db, 'system_from_email', $reviewOwner) ?: $reviewOwner;
    $threshold    = (float)engine_setting($db, 'classification_confidence_threshold', '0');
    $hardStops    = engine_csv(engine_setting($db, 'hard_stop_vendor_statuses', 'Blocked'));
    $sender       = (string)$case['real_sender_email'];
    $type         = (string)$case['request_type'];
    $supported    = ['procurement_request', 'vendor_onboarding', 'document_submission'];

    $confidence = (isset($context['confidence']) && is_numeric($context['confidence'])) ? (float)$context['confidence'] : null;
    $lowConf    = $confidence !== null && $confidence < $threshold;

    /* ---- gather facts (read-only) ---- */
    $vendor = find_vendor_by_email($db, $sender);
    $docs   = $vendor ? get_mandatory_document_issues($db, (int)$vendor['vendor_id'], (string)$vendor['vendor_status'], $businessDate) : null;
    $match  = find_matching_request($db, $case, $vendor, $context);
    $req    = $match['request'];
    $rule   = null;

    $why = [];
    $why[] = $vendor
        ? "The message was sent on behalf of $sender, who matches vendor {$vendor['vendor_name']} (status {$vendor['vendor_status']}, {$vendor['risk_level']} risk)."
        : "The sender $sender does not match any registered vendor.";
    if ($docs) {
        if (!$docs['missing'] && !$docs['expired'] && !$docs['pending']) {
            $why[] = 'All ' . count($docs['checks']) . ' mandatory documents are verified and in date as of ' . $businessDate . '.';
        } else {
            $parts = [];
            foreach (['expired' => 'Expired', 'missing' => 'Missing', 'pending' => 'Awaiting verification'] as $k => $label) {
                if ($docs[$k]) {
                    $parts[] = $label . ': ' . implode(', ', $docs[$k]);
                }
            }
            $why[] = 'Mandatory document check found issues - ' . implode('; ', $parts) . '.';
        }
    }
    if ($type === 'procurement_request') {
        $why[] = $match['matched']
            ? 'Matched procurement request #' . $req['request_id'] . ' for ' . nv_money($req['amount']) . ' (' . $req['category'] . ').'
            : 'No safe match to a procurement request: ' . $match['note'];
    }

    /* ---- precedence chain ---- */
    $status = $ruleId = $action = $reason = $owner = $nextAction = $mailTo = $mailType = null;
    $followUp = null;
    $manual = static function (string $rid, string $why1) use (&$status, &$ruleId, &$action, &$reason, &$owner, &$nextAction, &$mailTo, &$mailType, $reviewOwner): void {
        $status = NV_STATUS_MANUAL_REVIEW; $ruleId = $rid; $action = 'MANUAL_REVIEW'; $reason = $why1;
        $owner = $reviewOwner; $mailTo = $reviewOwner; $mailType = 'manual_review_escalation';
        $nextAction = 'Human review required: ' . $why1 . ' Escalated to ' . $reviewOwner . '.';
    };

    if ($vendor && in_array($vendor['vendor_status'], $hardStops, true)) {
        $manual('MR-001', "Vendor {$vendor['vendor_name']} is {$vendor['vendor_status']}; requests are never auto-approved.");
    } elseif ($docs && $docs['expired']) {
        $manual('MR-002', 'Mandatory document(s) expired: ' . implode(', ', $docs['expired']) . '.');
    } elseif ($vendor && $vendor['bank_verification_status'] === 'Failed') {
        $manual('MR-003', 'Bank verification has failed for this vendor.');
    } elseif ($vendor && $vendor['vendor_status'] === 'Manual Review') {
        $manual('MR-010', 'Vendor is already flagged for Manual Review.');
    } elseif (!$vendor) {
        $manual('MR-004', "No registered vendor matches sender $sender.");
    } elseif ($lowConf) {
        $manual('MR-005', sprintf('Request classification confidence %.2f is below the required %.2f.', $confidence, $threshold));
    } elseif (!in_array($type, $supported, true)) {
        $manual('MR-006', "Request type '" . ($type === '' ? 'unknown' : $type) . "' cannot be handled automatically.");
    } elseif ($type === 'procurement_request' && !$match['matched']) {
        $manual('MR-007', 'No safe match to a procurement request. ' . $match['note']);
    } elseif ($docs && ($docs['missing'] || $docs['pending'])) {
        $list = [];
        foreach (['missing' => 'missing', 'pending' => 'awaiting verification'] as $k => $label) {
            foreach ($docs[$k] as $d) {
                $list[] = "$d ($label)";
            }
        }
        $days = engine_require_setting($db, 'follow_up_days');
        if (!ctype_digit($days) || (int)$days > 365) {
            throw new EngineException("Setting 'follow_up_days' must be a whole number of days.", 'config_error');
        }
        $followUp = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate)->modify('+' . (int)$days . ' days')->format('Y-m-d');
        $status = NV_STATUS_PENDING_DOCS; $ruleId = NV_RULE_DOCS; $action = 'PENDING_DOCUMENTS';
        $reason = 'Mandatory document(s) outstanding: ' . implode(', ', $list) . '.';
        $owner = $systemOwner; $mailTo = $sender; $mailType = 'document_request';
        $nextAction = 'Await vendor documents: ' . implode(', ', $list) . '. Follow up by ' . $followUp . '.';
    } elseif ($vendor['vendor_status'] !== 'Active') {
        $manual('MR-009', "Vendor status is {$vendor['vendor_status']} (not Active) although documents are complete.");
    } elseif ($type === 'procurement_request') {
        $category = (string)$req['category'];
        $rule = find_approval_rule($db, number_format((float)$req['amount'], 2, '.', ''), (string)$vendor['risk_level'], $category);
        if ($rule === null) {
            $manual('MR-008', 'No approval rule covers ' . nv_money($req['amount']) . " for category '$category' at {$vendor['risk_level']} risk.");
        } elseif ((int)$rule['approval_required'] === 1) {
            $approver = trim((string)$rule['approver_email']);
            if ($approver === '') {
                $manual('MR-008', 'Approval rule ' . $rule['rule_id'] . ' has no approver configured.');
            } else {
                $status = NV_STATUS_PENDING_APPROVAL; $ruleId = 'AM-' . $rule['rule_id']; $action = 'PENDING_APPROVAL';
                $reason = nv_money($req['amount']) . ' requires approval under rule ' . $rule['rule_id'] . '.';
                $owner = $approver; $mailTo = $approver; $mailType = 'approval_request';
                $nextAction = 'Awaiting approval from ' . $approver . ' (rule ' . $rule['rule_id'] . ').';
            }
        } else {
            $status = NV_STATUS_APPROVED; $ruleId = 'AM-' . $rule['rule_id']; $action = 'AUTO_APPROVED';
            $reason = nv_money($req['amount']) . ' is within auto-process rule ' . $rule['rule_id'] . '; vendor is Active and fully compliant.';
            $owner = $systemOwner; $mailTo = $sender; $mailType = 'acknowledgement';
            $nextAction = 'Auto-processed under rule ' . $rule['rule_id'] . '; acknowledgement logged to ' . $sender . '.';
        }
    } else {   // vendor_onboarding / document_submission, vendor Active, all documents valid
        $status = NV_STATUS_APPROVED; $ruleId = 'DOC-OK-001'; $action = 'AUTO_APPROVED';
        $reason = 'All mandatory documents are verified and the vendor is Active.';
        $owner = $systemOwner; $mailTo = $sender; $mailType = 'acknowledgement';
        $nextAction = 'Documents verified; acknowledgement logged to ' . $sender . '.';
    }

    if ($rule !== null) {
        $why[] = 'Approval rule ' . $rule['rule_id'] . ' applies: ' . nv_money($rule['min_amount']) . ' to '
            . ($rule['max_amount'] === null ? 'no upper limit' : nv_money($rule['max_amount']))
            . ', ' . ((int)$rule['approval_required'] === 1 ? 'approval by ' . $rule['approver_email'] : 'no approval needed') . '.';
    }
    $why[] = 'Outcome: ' . $status . '. ' . $reason;

    /* ---- evidence ---- */
    $requestId = ($match['matched'] && $req) ? (int)$req['request_id'] : null;
    $vendorId  = $vendor ? (int)$vendor['vendor_id'] : null;
    $evidence = [
        'case_id' => $caseId,
        'real_sender_email' => $sender,
        'request_type' => $type,
        'vendor_match' => ['found' => $vendor !== null, 'vendor_id' => $vendorId, 'vendor_name' => $vendor['vendor_name'] ?? null, 'method' => 'exact_email'],
        'vendor_status' => $vendor['vendor_status'] ?? null,
        'risk_level' => $vendor['risk_level'] ?? null,
        'bank_verification_status' => $vendor['bank_verification_status'] ?? null,
        'document_checks' => $docs['checks'] ?? [],
        'classification' => ['confidence' => $confidence, 'threshold' => $threshold, 'below_threshold' => $lowConf],
        'request_match' => [
            'matched' => $match['matched'], 'request_id' => $requestId, 'method' => $match['method'], 'note' => $match['note'],
            'amount' => $req ? (float)$req['amount'] : null, 'category' => $req['category'] ?? null,
        ],
        'amount' => $req ? (float)$req['amount'] : null,
        'amount_in_message' => isset($context['amount']) && is_numeric($context['amount']) ? (float)$context['amount'] : null,
        'approval_rule' => $rule ? [
            'rule_id' => (int)$rule['rule_id'], 'min_amount' => (float)$rule['min_amount'],
            'max_amount' => $rule['max_amount'] === null ? null : (float)$rule['max_amount'],
            'risk_level' => $rule['risk_level'], 'category' => $rule['category'],
            'approver_email' => $rule['approver_email'], 'approval_required' => (int)$rule['approval_required'],
        ] : null,
        'outcome' => $status, 'rule_id' => $ruleId, 'reason' => $reason, 'owner' => $owner,
        'follow_up_date' => $followUp,
        'human_required' => in_array($status, [NV_STATUS_PENDING_APPROVAL, NV_STATUS_MANUAL_REVIEW], true),
        'business_date' => $businessDate,
        'timestamp' => gmdate('c'),
        'why' => $why,
    ];

    /* ---- the ONE state change: case + request status, ONE audit row, ONE mail row ---- */
    update_case_state($db, $caseId, $status, $nextAction, $owner, $vendorId, $requestId, $followUp);
    if ($requestId !== null) {
        engine_exec($db, 'UPDATE procurement_requests SET status = ? WHERE request_id = ?', 'si', [$status, $requestId]);
    }
    $auditId = insert_audit_log($db, $caseId, $action, $reason, $ruleId, $evidence);

    $subject = '[' . $caseId . '] ' . [
        'acknowledgement' => 'Request received and processed',
        'document_request' => 'Documents required to proceed',
        'approval_request' => 'Approval required',
        'manual_review_escalation' => 'Manual review required',
    ][$mailType];
    $body = "Case: $caseId\nStatus: $status\n\n$reason\n\nNext step: $nextAction\n\n- Novalite Procurement Assistant";
    $mailId = insert_mail_log($db, $caseId, $mailTo, $subject, $body, $mailType);

    return [
        'case_id' => $caseId, 'status' => $status, 'rule_id' => $ruleId, 'action' => $action, 'reason' => $reason,
        'owner' => $owner, 'next_action' => $nextAction, 'follow_up_date' => $followUp,
        'linked_vendor_id' => $vendorId, 'linked_request_id' => $requestId,
        'audit_id' => $auditId, 'mail_id' => $mailId, 'evidence' => $evidence,
    ];
}
