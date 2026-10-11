<?php
/**
 * cron/followup.php - chase / escalate Pending Documents cases.   CLI only:  php cron/followup.php
 *
 * Uses the STRUCTURED workflow_cases.follow_up_due_at (set by the decision engine), never next_action text.
 * For each due case, in ONE transaction per case (row locked, due-ness re-checked):
 *   - documents now complete/expired -> decide_case() re-evaluates (one audit row, one mail row)
 *   - chase_count <  settings.max_follow_up_chases -> chase mail, chase_count+1, next due = today + follow_up_days,
 *       exactly ONE audit row (FOLLOW_UP_CHASE)
 *   - chase_count >= settings.max_follow_up_chases -> Manual Review, owner = settings.manual_review_owner,
 *       escalation mail, exactly ONE audit row (FOLLOW_UP_ESCALATED)
 * "Today" is engine_business_date(): settings.business_date, or the database date when that setting is absent.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

const NV_FU_BATCH = 200;

function fu_log(string $msg): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . 'Z] ' . $msg . "\n";
}

/** @return string chased|escalated|reevaluated|skipped */
function fu_process_case(mysqli $db, string $caseId, string $today): string
{
    $db->begin_transaction();
    try {
        $c = engine_query_one($db,
            'SELECT case_id, real_sender_email, linked_vendor_id, linked_request_id, current_status, follow_up_due_at, chase_count
               FROM workflow_cases WHERE case_id = ? FOR UPDATE', 's', [$caseId]);
        if ($c === null || $c['current_status'] !== NV_STATUS_PENDING_DOCS
            || $c['follow_up_due_at'] === null || (string)$c['follow_up_due_at'] > $today) {
            $db->rollback();
            return 'skipped';                       // already handled by another run, or not due
        }

        $vendor = $c['linked_vendor_id'] !== null
            ? engine_query_one($db, 'SELECT vendor_id, vendor_name, vendor_status FROM vendors WHERE vendor_id = ?', 'i', [(int)$c['linked_vendor_id']])
            : null;
        $docs = $vendor !== null
            ? get_mandatory_document_issues($db, (int)$vendor['vendor_id'], (string)$vendor['vendor_status'], $today)
            : null;

        // Vendor sent the documents (or one expired meanwhile): let the engine decide again. One audit row.
        if ($docs !== null && !$docs['missing'] && !$docs['pending']) {
            decide_case($db, $caseId, [], true);
            $db->commit();
            return 'reevaluated';
        }

        $reviewOwner = engine_require_setting($db, 'manual_review_owner');
        $systemOwner = engine_setting($db, 'system_from_email', $reviewOwner) ?: $reviewOwner;
        $maxChases   = engine_require_setting($db, 'max_follow_up_chases');
        $days        = engine_require_setting($db, 'follow_up_days');
        if (!ctype_digit($maxChases) || !ctype_digit($days) || (int)$days > 365 || (int)$maxChases > 50) {
            throw new EngineException("Settings 'max_follow_up_chases' and 'follow_up_days' must be whole numbers.", 'config_error');
        }
        $outstanding = [];
        foreach (['missing' => 'missing', 'pending' => 'awaiting verification'] as $k => $label) {
            foreach (($docs[$k] ?? []) as $d) {
                $outstanding[] = "$d ($label)";
            }
        }
        $list   = $outstanding ? implode(', ', $outstanding) : 'unknown (vendor record not linked)';
        $sender = (string)$c['real_sender_email'];
        $count  = (int)$c['chase_count'];
        $due    = (string)$c['follow_up_due_at'];
        $requestId = $c['linked_request_id'] !== null ? (int)$c['linked_request_id'] : null;

        if ($count >= (int)$maxChases) {
            // ---- final deadline passed, still unresolved -> escalate ----
            $reason = "Mandatory documents still outstanding after $count follow-up(s); no response by $due: $list.";
            $next   = "Human review required: $reason Escalated to $reviewOwner.";
            update_case_state($db, $caseId, NV_STATUS_MANUAL_REVIEW, $next, $reviewOwner, null, null, null);
            if ($requestId !== null) {
                engine_exec($db, 'UPDATE procurement_requests SET status = ? WHERE request_id = ?', 'si', [NV_STATUS_MANUAL_REVIEW, $requestId]);
            }
            $evidence = [
                'case_id' => $caseId, 'outcome' => NV_STATUS_MANUAL_REVIEW, 'rule_id' => 'FU-002', 'reason' => $reason,
                'owner' => $reviewOwner, 'chase_count' => $count, 'overdue_since' => $due, 'outstanding_documents' => $outstanding,
                'human_required' => true, 'business_date' => $today, 'timestamp' => gmdate('c'),
                'why' => [
                    "Documents were requested and $count follow-up(s) were sent.",
                    "The follow-up date $due has passed and these are still outstanding: $list.",
                    "The case is now in Manual Review with $reviewOwner.",
                ],
            ];
            insert_audit_log($db, $caseId, 'FOLLOW_UP_ESCALATED', $reason, 'FU-002', $evidence);
            insert_mail_log($db, $caseId, $reviewOwner, "[$caseId] Overdue documents - manual review required",
                "Case: $caseId\nStatus: " . NV_STATUS_MANUAL_REVIEW . "\n\n$reason\n\n- Novalite Procurement Assistant", 'manual_review_escalation');
            $db->commit();
            return 'escalated';
        }

        // ---- chase ----
        $nextDue = DateTimeImmutable::createFromFormat('!Y-m-d', $today)->modify('+' . (int)$days . ' days')->format('Y-m-d');
        $n       = $count + 1;
        $reason  = "Follow-up $n of $maxChases sent: documents still outstanding - $list.";
        $next    = "Await vendor documents: $list. Follow-up $n of $maxChases sent; next check on $nextDue.";
        engine_exec($db,
            'UPDATE workflow_cases SET next_action = ?, follow_up_due_at = ?, chase_count = ?, owner = ? WHERE case_id = ?',
            'ssiss', [mb_substr($next, 0, 500), $nextDue, $n, $systemOwner, $caseId]);
        $evidence = [
            'case_id' => $caseId, 'outcome' => NV_STATUS_PENDING_DOCS, 'rule_id' => 'FU-001', 'reason' => $reason,
            'owner' => $systemOwner, 'chase_count' => $n, 'max_chases' => (int)$maxChases, 'next_follow_up_due_at' => $nextDue,
            'outstanding_documents' => $outstanding, 'human_required' => false, 'business_date' => $today, 'timestamp' => gmdate('c'),
            'why' => [
                "The follow-up date $due has arrived and documents are still outstanding: $list.",
                "Chase $n of $maxChases was sent to $sender.",
                "The next check is on $nextDue.",
            ],
        ];
        insert_audit_log($db, $caseId, 'FOLLOW_UP_CHASE', $reason, 'FU-001', $evidence);
        insert_mail_log($db, $caseId, $sender, "[$caseId] Reminder: documents still required",
            "Case: $caseId\n\nReminder $n of $maxChases. We are still waiting for: $list.\nPlease send them by $nextDue to avoid escalation.\n\n- Novalite Procurement Assistant", 'follow_up_chase');
        $db->commit();
        return 'chased';
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

$exit = 0;
try {
    $db = db();
    $lock = engine_query_one($db, 'SELECT GET_LOCK(?, 0) AS got', 's', ['novalite_followup']);
    if ((int)($lock['got'] ?? 0) !== 1) {
        fu_log('another follow-up run is active; exiting');
        exit(0);
    }
    $today = engine_business_date($db);
    $ids = engine_query($db,
        'SELECT case_id FROM workflow_cases
          WHERE current_status = ? AND follow_up_due_at IS NOT NULL AND follow_up_due_at <= ?
          ORDER BY follow_up_due_at, case_id LIMIT ' . NV_FU_BATCH, 'ss', [NV_STATUS_PENDING_DOCS, $today]);

    $tally = ['chased' => 0, 'escalated' => 0, 'reevaluated' => 0, 'skipped' => 0, 'errors' => 0];
    foreach ($ids as $row) {
        try {
            $r = fu_process_case($db, (string)$row['case_id'], $today);
            $tally[$r]++;
            fu_log("{$row['case_id']}: $r");
        } catch (Throwable $e) {
            $tally['errors']++;
            $exit = 1;
            error_log('[novalite-cron] ' . $row['case_id'] . ': ' . $e->getMessage());
            fu_log("{$row['case_id']}: error (see server log)");
        }
    }
    fu_log("business_date=$today due=" . count($ids) . ' chased=' . $tally['chased'] . ' escalated=' . $tally['escalated']
        . ' reevaluated=' . $tally['reevaluated'] . ' skipped=' . $tally['skipped'] . ' errors=' . $tally['errors']);
    engine_query($db, 'SELECT RELEASE_LOCK(?)', 's', ['novalite_followup']);
} catch (Throwable $e) {
    error_log('[novalite-cron] fatal: ' . $e->getMessage());
    fu_log('fatal error (see server log)');
    $exit = 1;
}
exit($exit);
