<?php
/**
 * GET api/case.php?id=VP-1001 - Evidence Card plus the raw operational data behind it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/evidence.php';

try {
    require_method('GET');
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '' || !nv_valid_case_id($id)) {
        throw new EngineException("'id' is required (letters, digits, - or _, max 20 characters).", 'validation_error');
    }

    $db = db();
    $card = build_evidence_card($db, $id);          // throws case_not_found -> 404

    $case = engine_query_one($db,
        'SELECT case_id, thread_id, real_sender_email, request_type, linked_vendor_id, linked_request_id,
                current_status, next_action, owner, follow_up_due_at, chase_count, created_at, updated_at
           FROM workflow_cases WHERE case_id = ?', 's', [$id]);

    $vendor = $case['linked_vendor_id'] !== null
        ? engine_query_one($db,
            'SELECT vendor_id, vendor_name, vendor_email, gst_id, vendor_status, risk_level, bank_verification_status, created_at
               FROM vendors WHERE vendor_id = ?', 'i', [(int)$case['linked_vendor_id']])
        : null;

    $request = $case['linked_request_id'] !== null
        ? engine_query_one($db,
            'SELECT request_id, requester_email, vendor_id, category, amount, status, required_by_date, created_at
               FROM procurement_requests WHERE request_id = ?', 'i', [(int)$case['linked_request_id']])
        : null;

    $documents = $vendor !== null
        ? engine_query($db,
            'SELECT vd.document_type, vd.verification_status, vd.expiry_date, dr.is_mandatory, dr.active
               FROM vendor_documents vd JOIN document_rules dr ON dr.document_type = vd.document_type
              WHERE vd.vendor_id = ? ORDER BY dr.sort_order, vd.document_type', 'i', [(int)$vendor['vendor_id']])
        : [];

    $audit = engine_query($db,
        'SELECT log_id, case_id, action, decision_reason, rule_id, actor, evidence, created_at
           FROM audit_log WHERE case_id = ? ORDER BY created_at ASC, log_id ASC', 's', [$id]);
    foreach ($audit as &$a) {
        $decoded = ($a['evidence'] !== null && $a['evidence'] !== '') ? json_decode((string)$a['evidence'], true) : null;
        $a['evidence'] = is_array($decoded) ? $decoded : null;
    }
    unset($a);

    $mail = engine_query($db,
        'SELECT mail_id, case_id, recipient_email, subject, body, mail_type, created_at
           FROM mail_log WHERE case_id = ? ORDER BY created_at ASC, mail_id ASC', 's', [$id]);

    json_out([
        'evidence_card' => $card,
        'case' => $case + ['status' => nv_status_label((string)$case['current_status'])],
        'vendor' => $vendor ?? new stdClass(),
        'procurement_request' => $request ?? new stdClass(),
        'documents' => $documents,
        'audit_trail' => $audit,
        'mail_history' => $mail,
    ], 200);
} catch (Throwable $e) {
    nv_fail($e);
}
