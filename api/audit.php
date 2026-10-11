<?php
/**
 * GET api/audit.php?search=&page=
 * Full immutable audit trail history.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

try {
    require_method('GET');
    $db = db();

    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $like = '%' . strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $where[] = '(a.case_id LIKE ? OR a.action LIKE ? OR a.rule_id LIKE ? OR a.actor LIKE ? OR a.decision_reason LIKE ?)';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)engine_query_one($db, "SELECT COUNT(*) as n FROM audit_log a $whereSql", $types, $params)['n'];

    $rows = engine_query($db,
        "SELECT a.log_id, a.case_id, a.action, a.rule_id, a.actor, a.decision_reason, a.created_at
         FROM audit_log a
         $whereSql
         ORDER BY a.log_id DESC
         LIMIT ? OFFSET ?",
        $types . 'ii', array_merge($params, [$perPage, $offset])
    );

    json_out([
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => (int)ceil($total / $perPage),
    ]);
} catch (Throwable $e) {
    nv_fail($e);
}
