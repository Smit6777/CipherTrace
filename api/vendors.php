<?php
/**
 * GET api/vendors.php
 * List all registered vendors with compliance documents health.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../engine/decide.php';

try {
    require_method('GET');
    $db = db();

    $vendors = engine_query($db, "SELECT vendor_id, vendor_name, vendor_email, gst_id, vendor_status, risk_level, bank_verification_status, created_at FROM vendors ORDER BY vendor_id ASC");

    foreach ($vendors as &$v) {
        $vid = (int)$v['vendor_id'];
        $docs = engine_query($db, "SELECT document_type, verification_status, expiry_date FROM vendor_documents WHERE vendor_id = ?", 'i', [$vid]);
        $v['documents'] = $docs;
    }
    unset($v);

    json_out(['vendors' => $vendors]);
} catch (Throwable $e) {
    nv_fail($e);
}
