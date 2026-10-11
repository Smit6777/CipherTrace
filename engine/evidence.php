<?php
/**
 * engine/evidence.php - read-only "evidence card" built from persisted data.
 * The latest audit_log row is the authoritative source of decision and reasons.
 */
declare(strict_types=1);

require_once __DIR__ . '/decide.php';   // shared DB helpers + EngineException

/**
 * @return array{case_id:string,vendor:array,request_type:string,status:string,decision:string,why:array,
 *               rule_applied:string,next_action:string,human_needed:bool,audit_timestamp:string}
 * @throws EngineException
 */
function build_evidence_card(mysqli $db, string $caseId): array
{
    $caseId = trim($caseId);
    $case = engine_query_one($db,
        'SELECT case_id, thread_id, real_sender_email, request_type, linked_vendor_id, linked_request_id,
                current_status, next_action, owner, created_at
           FROM workflow_cases WHERE case_id = ?', 's', [$caseId]);
    if ($case === null) {
        throw new EngineException("Case $caseId was not found.", 'case_not_found');
    }

    $vendor = $case['linked_vendor_id'] !== null
        ? engine_query_one($db, 'SELECT vendor_id, vendor_name, vendor_email, vendor_status, risk_level FROM vendors WHERE vendor_id = ?',
            'i', [(int)$case['linked_vendor_id']])
        : null;

    $audit = engine_query_one($db,
        'SELECT log_id, action, decision_reason, rule_id, actor, evidence, created_at
           FROM audit_log WHERE case_id = ? ORDER BY log_id DESC LIMIT 1', 's', [$caseId]);

    $ev = [];
    if ($audit !== null && $audit['evidence'] !== null && $audit['evidence'] !== '') {
        $decoded = json_decode((string)$audit['evidence'], true);
        $ev = is_array($decoded) ? $decoded : [];
    }

    $status = (string)$case['current_status'];
    $decisionLabels = [
        'AUTO_APPROVED'     => 'Approved / Auto-processed',
        'PENDING_DOCUMENTS' => 'Pending Documents',
        'PENDING_APPROVAL'  => 'Pending Approval',
        'MANUAL_REVIEW'     => 'Manual Review',
    ];

    if ($audit === null) {
        $decision = '';
        $why = ['No decision has been recorded for this case yet.'];
        $rule = '';
        $stamp = '';
    } else {
        $decision = $decisionLabels[$audit['action']] ?? $status;
        $why = [];
        if (isset($ev['why']) && is_array($ev['why'])) {
            foreach ($ev['why'] as $line) {
                if (is_string($line) && trim($line) !== '') {
                    $why[] = trim($line);
                }
            }
        }
        if (!$why) {
            $why[] = trim((string)$audit['decision_reason']) !== '' ? trim((string)$audit['decision_reason']) : 'No reason was recorded.';
        }
        $rule = (string)($audit['rule_id'] ?? '');
        if (isset($ev['approval_rule']) && is_array($ev['approval_rule'])) {
            $r = $ev['approval_rule'];
            $rule .= ' - amount band ' . nv_money($r['min_amount'] ?? 0) . ' to '
                . (($r['max_amount'] ?? null) === null ? 'no upper limit' : nv_money($r['max_amount']))
                . ((int)($r['approval_required'] ?? 0) === 1 ? ', approval required' : ', no approval required');
        }
        $stamp = (string)$audit['created_at'];
    }

    // numbered, simple business language
    $numbered = [];
    foreach (array_values($why) as $i => $line) {
        $numbered[] = ($i + 1) . '. ' . preg_replace('/^\d+\.\s*/', '', $line);
    }

    // human_needed
    if ($status === NV_STATUS_PENDING_APPROVAL || $status === NV_STATUS_MANUAL_REVIEW) {
        $human = true;
    } elseif ($status === NV_STATUS_PENDING_DOCS) {
        $reviewOwner = engine_setting($db, 'manual_review_owner');
        $human = (isset($ev['human_required']) && $ev['human_required'] === true)
            || ($reviewOwner !== null && $case['owner'] !== null && strcasecmp(trim($reviewOwner), (string)$case['owner']) === 0);
    } else {
        $human = false;
    }

    return [
        'case_id' => (string)$case['case_id'],
        'vendor' => [
            'vendor_id'     => $vendor ? (int)$vendor['vendor_id'] : null,
            'vendor_name'   => $vendor ? (string)$vendor['vendor_name'] : '',
            'vendor_email'  => $vendor ? (string)$vendor['vendor_email'] : '',
            'vendor_status' => $vendor ? (string)$vendor['vendor_status'] : '',
            'risk_level'    => $vendor ? (string)$vendor['risk_level'] : '',
        ],
        'request_type'    => (string)$case['request_type'],
        'status'          => $status,
        'decision'        => $decision,
        'why'             => $numbered,
        'rule_applied'    => $rule,
        'next_action'     => (string)($case['next_action'] ?? ''),
        'human_needed'    => $human,
        'audit_timestamp' => $stamp,
    ];
}
