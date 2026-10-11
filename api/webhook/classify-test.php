<?php
/**
 * POST api/webhook/classify-test.php - parse + classify only. Creates NO case and writes NOTHING.
 * (extract_real_sender reads contact_map to refuse pack mailboxes; that is a read-only lookup.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../engine/parse.php';
require_once __DIR__ . '/../../engine/classify.php';

try {
    require_method('POST');
    $in = read_json_body();

    $from    = strtolower((string)nv_input_string($in, 'from', true, 190));
    $subject = (string)nv_input_string($in, 'subject', false, 255);
    $body    = $in['body'] ?? null;
    if (!is_string($body) || trim($body) === '') {
        throw new EngineException("'body' is required.", 'validation_error');
    }
    if (mb_strlen($body) > 100000) {
        throw new EngineException("'body' must be at most 100000 characters.", 'validation_error');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new EngineException("'from' must be a valid email address.", 'validation_error');
    }

    $real = extract_real_sender(db(), $body, $from);
    $cls  = classify_request($subject, $body);

    json_out([
        'real_sender' => ['name' => $real['name'], 'email' => $real['email'], 'source' => $real['source']],
        'classification' => $cls,
    ], 200);
} catch (Throwable $e) {
    nv_fail($e);
}
