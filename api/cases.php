<?php
/**
 * GET api/cases.php?search=&status=&sort=&page=   (20 per page)
 * search: case_id, real_sender_email, vendor_name, vendor_email.  sort: asc|desc on created_at (default desc).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

const NV_PER_PAGE = 20;

try {
    require_method('GET');

    // Allowlisted values only - never interpolate request data into SQL.
    $allowedStatus = [
        'New' => 'New', NV_STATUS_APPROVED => NV_STATUS_APPROVED, 'Approved/Auto-processed' => NV_STATUS_APPROVED,
        NV_STATUS_PENDING_DOCS => NV_STATUS_PENDING_DOCS, NV_STATUS_PENDING_APPROVAL => NV_STATUS_PENDING_APPROVAL,
        NV_STATUS_MANUAL_REVIEW => NV_STATUS_MANUAL_REVIEW, NV_STATUS_REJECTED => NV_STATUS_REJECTED,
    ];

    $search = trim((string)($_GET['search'] ?? ''));
    if (mb_strlen($search) > 100) {
        throw new EngineException("'search' must be at most 100 characters.", 'validation_error');
    }
    $statusIn = trim((string)($_GET['status'] ?? ''));
    if ($statusIn !== '' && !isset($allowedStatus[$statusIn])) {
        throw new EngineException("'status' must be one of: " . implode(', ', array_keys($allowedStatus)) . '.', 'validation_error');
    }
    $sortIn = strtolower(trim((string)($_GET['sort'] ?? 'desc')));
    if ($sortIn !== 'asc' && $sortIn !== 'desc') {
        throw new EngineException("'sort' must be asc or desc.", 'validation_error');
    }
    $dir = $sortIn === 'asc' ? 'ASC' : 'DESC';      // literal chosen from an allowlist
    $pageRaw = $_GET['page'] ?? '1';
    if (!is_string($pageRaw) || preg_match('/^[1-9][0-9]{0,6}$/', $pageRaw) !== 1) {
        throw new EngineException("'page' must be a positive integer.", 'validation_error');
    }
    $page = (int)$pageRaw;

    $where = [];
    $types = '';
    $params = [];
    if ($search !== '') {
        $like = '%' . strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $where[] = '(c.case_id LIKE ? OR c.real_sender_email LIKE ? OR v.vendor_name LIKE ? OR v.vendor_email LIKE ?)';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($statusIn !== '') {
        $where[] = 'c.current_status = ?';
        $types .= 's';
        $params[] = $allowedStatus[$statusIn];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $from = 'FROM workflow_cases c LEFT JOIN vendors v ON v.vendor_id = c.linked_vendor_id';

    $db = db();
    $total = (int)engine_query_one($db, "SELECT COUNT(*) AS n $from $whereSql", $types, $params)['n'];
    $totalPages = (int)ceil($total / NV_PER_PAGE);
    $offset = ($page - 1) * NV_PER_PAGE;

    $rows = engine_query($db,
        "SELECT c.case_id, c.thread_id, c.real_sender_email, c.request_type, c.current_status, c.next_action, c.owner,
                c.follow_up_due_at, c.created_at, v.vendor_name, v.vendor_email
           $from $whereSql
          ORDER BY c.created_at $dir, c.case_id $dir
          LIMIT ? OFFSET ?",
        $types . 'ii', array_merge($params, [NV_PER_PAGE, $offset]));
    foreach ($rows as &$r) {
        $r['status'] = nv_status_label((string)$r['current_status']);
    }
    unset($r);

    json_out(['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => NV_PER_PAGE, 'totalPages' => $totalPages], 200);
} catch (Throwable $e) {
    nv_fail($e);
}
