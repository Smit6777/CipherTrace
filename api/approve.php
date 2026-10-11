<?php
/**
 * POST api/approve.php - APPROVE / REJECT a case that is Pending Approval.
 * Identify the case by case_id OR thread_id (approval reply threading; never creates a case).
 * The approver is verified against the approval_matrix rule for the case - the client is not trusted.
 * One transaction: case + request status, ONE audit_log row, ONE mail_log row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

try {
    require_method('POST');
    $in = read_json_body();

    $action = $in['action'] ?? null;
    if ($action !== 'APPROVE' && $action !== 'REJECT') {
        throw new EngineException("'action' must be exactly APPROVE or REJECT.", 'validation_error');
    }
    $approver = strtolower((string)nv_input_string($in, 'approver', true, 190));
    if (!filter_var($approver, FILTER_VALIDATE_EMAIL)) {
        throw new EngineException("'approver' must be a valid email address.", 'validation_error');
    }
    $caseIdIn = nv_input_string($in, 'case_id', false, 20);
    $threadIn = nv_input_string($in, 'thread_id', false, 100, '/^[A-Za-z0-9._:@\-]+$/');
    if ($caseIdIn === null && $threadIn === null) {
        throw new EngineException("Provide 'case_id' or 'thread_id'.", 'validation_error');
    }
    if ($caseIdIn !== null && !nv_valid_case_id($caseIdIn)) {
        throw new EngineException("'case_id' has an invalid format.", 'validation_error');
    }

    $db = db();
    $db->begin_transaction();
    try {
        $cols = 'case_id, thread_id, real_sender_email, request_type, linked_vendor_id, linked_request_id, current_status, owner';
        if ($caseIdIn !== null) {
            $case = engine_query_one($db, "SELECT $cols FROM workflow_cases WHERE case_id = ? FOR UPDATE", 's', [$caseIdIn]);
        } else {
            $case = engine_query_one($db, "SELECT $cols FROM workflow_cases WHERE thread_id = ? FOR UPDATE", 's', [$threadIn]);
        }
        if ($case === null) {
            throw new EngineException('Case not found.', 'case_not_found');
        }
        if ($caseIdIn !== null && $threadIn !== null && $case['thread_id'] !== $threadIn) {
            throw new EngineException("'thread_id' does not belong to case $caseIdIn.", 'conflict');
        }
        $caseId = (string)$case['case_id'];
        if ($case['current_status'] !== NV_STATUS_PENDING_APPROVAL) {
            throw new EngineException("Case $caseId is '{$case['current_status']}', not '" . NV_STATUS_PENDING_APPROVAL . "'.", 'conflict');
        }
        if ($case['linked_request_id'] === null || $case['linked_vendor_id'] === null) {
            throw new EngineException("Case $caseId has no linked request/vendor, so its approval rule cannot be determined.", 'conflict');
        }

        $req = engine_query_one($db,
            'SELECT request_id, category, amount FROM procurement_requests WHERE request_id = ? FOR UPDATE',
            'i', [(int)$case['linked_request_id']]);
        $vendor = engine_query_one($db,
            'SELECT vendor_id, vendor_name, risk_level FROM vendors WHERE vendor_id = ?', 'i', [(int)$case['linked_vendor_id']]);
        if ($req === null || $vendor === null) {
            throw new EngineException("Case $caseId links to a missing request or vendor.", 'conflict');
        }

        $rule = find_approval_rule($db, number_format((float)$req['amount'], 2, '.', ''), (string)$vendor['risk_level'], (string)$req['category']);
        if ($rule === null || (int)$rule['approval_required'] !== 1) {
            throw new EngineException("No approval-required rule currently applies to case $caseId.", 'conflict');
        }
        $allowed = array_map('strtolower', engine_csv((string)$rule['approver_email']));
        if (!in_array($approver, $allowed, true)) {
            throw new EngineException("$approver is not an authorised approver for rule {$rule['rule_id']}.", 'forbidden');
        }

        $reviewOwner = engine_require_setting($db, 'manual_review_owner');
        $systemOwner = engine_setting($db, 'system_from_email', $reviewOwner) ?: $reviewOwner;
        $sender      = (string)$case['real_sender_email'];
        $money       = nv_money($req['amount']);
        $ruleId      = 'AM-' . $rule['rule_id'];

        if ($action === 'APPROVE') {
            $status = NV_STATUS_APPROVED; $auditAction = 'APPROVED'; $mailType = 'acknowledgement';
            $reason = "$approver approved $money (rule {$rule['rule_id']}).";
            $next   = "Approved by $approver under rule {$rule['rule_id']}; acknowledgement logged to $sender.";
            $subject = "[$caseId] Request approved";
            $message = "Case $caseId approved by $approver.";
        } else {
            $status = NV_STATUS_REJECTED; $auditAction = 'REJECTED'; $mailType = 'rejection_notice';
            $reason = "$approver rejected $money (rule {$rule['rule_id']}).";
            $next   = "Rejected by $approver under rule {$rule['rule_id']}; notification logged to $sender.";
            $subject = "[$caseId] Request rejected";
            $message = "Case $caseId rejected by $approver.";
        }

        $evidence = [
            'case_id' => $caseId, 'decision' => $action, 'approver' => $approver,
            'previous_status' => NV_STATUS_PENDING_APPROVAL, 'outcome' => $status, 'rule_id' => $ruleId,
            'reason' => $reason, 'owner' => $systemOwner, 'amount' => (float)$req['amount'],
            'approval_rule' => [
                'rule_id' => (int)$rule['rule_id'], 'min_amount' => (float)$rule['min_amount'],
                'max_amount' => $rule['max_amount'] === null ? null : (float)$rule['max_amount'],
                'risk_level' => $rule['risk_level'], 'category' => $rule['category'],
                'approver_email' => $rule['approver_email'], 'approval_required' => 1,
            ],
            'human_required' => false, 'business_date' => engine_business_date($db), 'timestamp' => gmdate('c'),
            'why' => [
                "Case $caseId was waiting for approval of $money under rule {$rule['rule_id']}.",
                "Authorised approver $approver chose $action.",
                'Outcome: ' . $status . '.',
            ],
        ];

        update_case_state($db, $caseId, $status, $next, $systemOwner, null, null, null);
        engine_exec($db, 'UPDATE procurement_requests SET status = ? WHERE request_id = ?', 'si', [$status, (int)$req['request_id']]);
        insert_audit_log($db, $caseId, $auditAction, $reason, $ruleId, $evidence, $approver);
        insert_mail_log($db, $caseId, $sender, $subject,
            "Case: $caseId\nStatus: $status\n\n$reason\n\n- Novalite Procurement Assistant", $mailType);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    json_out([
        'case_id' => $caseId,
        'status' => nv_status_label($status),
        'decision' => $action,
        'owner' => $systemOwner,
        'message' => $message,
    ], 200);
} catch (Throwable $e) {
    nv_fail($e);
}
