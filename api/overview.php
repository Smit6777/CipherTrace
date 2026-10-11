<?php
/**
 * GET api/overview.php
 * Provides dashboard stat counters, recent queue, and compliance alerts.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

try {
    require_method('GET');
    $db = db();

    // 1. Stat Counters
    $stats = [
        'total' => 0,
        'pending_docs' => 0,
        'pending_approval' => 0,
        'manual_review' => 0,
        'auto_processed' => 0,
    ];

    $counts = engine_query($db, "SELECT current_status, COUNT(*) as cnt FROM workflow_cases GROUP BY current_status");
    foreach ($counts as $c) {
        $st = $c['current_status'];
        $n = (int)$c['cnt'];
        $stats['total'] += $n;
        if ($st === NV_STATUS_PENDING_DOCS) $stats['pending_docs'] += $n;
        elseif ($st === NV_STATUS_PENDING_APPROVAL) $stats['pending_approval'] += $n;
        elseif ($st === NV_STATUS_MANUAL_REVIEW) $stats['manual_review'] += $n;
        elseif ($st === NV_STATUS_APPROVED) $stats['auto_processed'] += $n;
    }

    // 2. Pending Approvals Queue
    $pendingQueue = engine_query($db, 
        "SELECT c.case_id, c.real_sender_email, c.owner, c.created_at, v.vendor_name, p.amount, p.category
         FROM workflow_cases c
         LEFT JOIN vendors v ON v.vendor_id = c.linked_vendor_id
         LEFT JOIN procurement_requests p ON p.request_id = c.linked_request_id
         WHERE c.current_status = ?
         ORDER BY c.created_at DESC LIMIT 5",
        's', [NV_STATUS_PENDING_APPROVAL]
    );

    // 3. Upcoming Follow-ups (Compliance Watchlist)
    $followUps = engine_query($db,
        "SELECT c.case_id, c.real_sender_email, c.follow_up_due_at, c.next_action, v.vendor_name
         FROM workflow_cases c
         LEFT JOIN vendors v ON v.vendor_id = c.linked_vendor_id
         WHERE c.current_status = ? AND c.follow_up_due_at IS NOT NULL
         ORDER BY c.follow_up_due_at ASC LIMIT 5",
        's', [NV_STATUS_PENDING_DOCS]
    );

    // 4. Recent Cases
    $recentCases = engine_query($db,
        "SELECT c.case_id, c.real_sender_email, c.current_status, c.created_at, v.vendor_name
         FROM workflow_cases c
         LEFT JOIN vendors v ON v.vendor_id = c.linked_vendor_id
         ORDER BY c.created_at DESC LIMIT 6"
    );
    foreach ($recentCases as &$rc) {
        $rc['status'] = nv_status_label((string)$rc['current_status']);
    }
    unset($rc);

    // 5. Recent Audit Activity
    $recentAudit = engine_query($db,
        "SELECT a.log_id, a.case_id, a.action, a.rule_id, a.actor, a.decision_reason, a.created_at
         FROM audit_log a
         ORDER BY a.log_id DESC LIMIT 6"
    );

    json_out([
        'stats' => $stats,
        'pending_queue' => $pendingQueue,
        'follow_ups' => $followUps,
        'recent_cases' => $recentCases,
        'recent_audit' => $recentAudit,
    ]);
} catch (Throwable $e) {
    nv_fail($e);
}
