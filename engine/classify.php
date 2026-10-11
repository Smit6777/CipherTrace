<?php
/**
 * engine/classify.php - deterministic keyword/regex classifier (prototype).
 * Pure function: no database, no output.
 */
declare(strict_types=1);

/** Remove the "On behalf of" tag line so a person's name cannot influence classification. */
function classify_strip_tag(string $body): string
{
    $lines = preg_split('/\R/', $body) ?: [];
    foreach ($lines as $i => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/On behalf of:\s*.+?\s*<[^>]+>/i', $line) === 1) {
            unset($lines[$i]);
        }
        break;
    }
    return implode("\n", $lines);
}

/** Parse rupee amounts (₹50,000 | Rs 50000 | INR 50,000 | 50000 INR | 2 lakh). Null if none or ambiguous. */
function classify_extract_amount(string $text, array &$kw): ?float
{
    $unit = '(?:\s*(?<u>lakhs?|lacs?|crores?|cr|k)(?![a-z]))?';
    $num  = '(?<n>[0-9][0-9,]*(?:\.[0-9]{1,2})?)';
    $pats = [
        '/(?<![a-z])(?:₹|rs\.?|inr)\s*' . $num . $unit . '/i',
        '/(?<![0-9a-z.,])' . $num . $unit . '\s*(?:inr|rupees?|rs\.?(?![a-z])|\/-)/i',
    ];
    $found = [];
    foreach ($pats as $p) {
        if (preg_match_all($p, $text, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $n = (float)str_replace(',', '', rtrim($m['n'], ','));
                $u = strtolower($m['u'] ?? '');
                if ($u === 'lakh' || $u === 'lakhs' || $u === 'lac' || $u === 'lacs') {
                    $n *= 100000;
                } elseif ($u === 'crore' || $u === 'crores' || $u === 'cr') {
                    $n *= 10000000;
                } elseif ($u === 'k') {
                    $n *= 1000;
                }
                if ($n > 0) {
                    $found[number_format($n, 2, '.', '')] = $n;
                }
            }
        }
    }
    if (count($found) === 0) {
        return null;
    }
    if (count($found) > 1) {
        $kw[] = 'multiple_amounts';
        return null;                                   // never guess between amounts
    }
    $kw[] = 'amount';
    return (float)array_values($found)[0];
}

/** Single explicit reference only (e.g. "PO #12", "Request ID: 7", "PR-45"); else null. */
function classify_extract_ref(string $text, array $patterns, string $label, array &$kw): ?int
{
    $vals = [];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $text, $ms)) {
            foreach ($ms[1] as $v) {
                $vals[(int)$v] = true;
            }
        }
    }
    unset($vals[0]);
    if (count($vals) === 1) {
        $kw[] = $label;
        return (int)array_key_first($vals);
    }
    if (count($vals) > 1) {
        $kw[] = 'multiple_' . $label;
    }
    return null;
}

/** @return 'APPROVE'|'REJECT'|null|false  false = conflicting/negated (ambiguous) */
function classify_approval_action(string $subject, string $text, array &$kw, bool &$standalone)
{
    $standalone = false;
    $approve = false;
    $reject = false;

    if (preg_match('/(?:\b(?:not|never)|n\'t)\s+(?:be\s+)?(?:approv|reject|declin)/i', $text) === 1) {
        $kw[] = 'negated_approval';
        return false;
    }
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $l = trim($line);
        if ($l === '') {
            continue;
        }
        if (preg_match('/^(?:yes[,\s]+)?(?:i\s+)?(?:approved?|approve it)[\s.!]*$/i', $l)) {
            $approve = true; $standalone = true; $kw[] = 'standalone_approve';
        }
        if (preg_match('/^(?:no[,\s]+)?(?:i\s+)?(?:reject(?:ed)?|declined?|denied)[\s.!]*$/i', $l)) {
            $reject = true; $standalone = true; $kw[] = 'standalone_reject';
        }
    }
    if (preg_match('/\b(?:i|we)\s+(?:hereby\s+)?approve\b|\bapproval\s+(?:is\s+)?granted\b|\b(?:request|po|purchase order|payment)\s+(?:is\s+|has been\s+)?approved\b/i', $text . ' ' . $subject)) {
        $approve = true; $kw[] = 'approve_phrase';
    }
    if (preg_match('/\b(?:i|we)\s+(?:hereby\s+)?(?:reject|decline)\b|\b(?:request|po|purchase order|payment)\s+(?:is\s+|has been\s+)?(?:rejected|declined)\b/i', $text . ' ' . $subject)) {
        $reject = true; $kw[] = 'reject_phrase';
    }
    if ($approve && $reject) {
        $kw[] = 'conflicting_approval';
        return false;
    }
    return $approve ? 'APPROVE' : ($reject ? 'REJECT' : null);
}

/**
 * @return array{request_type:string,confidence:float,extracted:array}
 */
function classify_request(string $subject, string $body): array
{
    $subject = trim($subject);
    $text = classify_strip_tag($body);
    $all = $subject . "\n" . $text;
    $kw = [];

    $extracted = [
        'request_id' => null, 'vendor_id' => null, 'amount' => null,
        'category' => null, 'approval_action' => null, 'matched_keywords' => [],
    ];

    // ---- extraction (independent of type) ----
    $extracted['amount'] = classify_extract_amount($all, $kw);
    $extracted['request_id'] = classify_extract_ref($all, [
        '/\b(?:request|req|po|pr|purchase\s+order|requisition)\s*(?:id|no\.?|number|ref(?:erence)?)\s*[:#.\-]?\s*(\d{1,9})\b/i',
        '/\b(?:request|req|po|pr|purchase\s+order|requisition)\s*#\s*(\d{1,9})\b/i',
        '/\b(?:PR|PO|REQ)-(\d{1,9})\b/i',
    ], 'request_ref', $kw);
    $extracted['vendor_id'] = classify_extract_ref($all, [
        '/\bvendor\s*(?:id|code|no\.?|number)\s*[:#.\-]?\s*(\d{1,9})\b/i',
    ], 'vendor_ref', $kw);
    if (preg_match('/\bcategory\s*[:=\-]\s*([A-Za-z][^\r\n,;.]{1,59})/i', $all, $cm)) {
        $extracted['category'] = trim($cm[1]);
        $kw[] = 'category';
    }

    // ---- approval reply (checked first; only strong, unambiguous signals) ----
    $standalone = false;
    $action = classify_approval_action($subject, $text, $kw, $standalone);
    if ($action === 'APPROVE' || $action === 'REJECT') {
        $extracted['approval_action'] = $action;
        $extracted['matched_keywords'] = array_values(array_unique($kw));
        return ['request_type' => 'approval_reply', 'confidence' => $standalone ? 0.95 : 0.85, 'extracted' => $extracted];
    }
    if ($action === false) {
        $extracted['matched_keywords'] = array_values(array_unique($kw));
        return ['request_type' => 'unknown', 'confidence' => 0.2, 'extracted' => $extracted];
    }

    // ---- weighted keyword scoring ----
    $rules = [
        'vendor_onboarding' => [
            ['/\bonboard(?:ing)?\b/i', 2, 'onboarding'],
            ['/\bvendor\s+(?:registration|application|enrol(?:l)?ment)\b/i', 2, 'vendor_registration'],
            ['/\b(?:register|empanel(?:l)?ed?)\b.{0,30}\b(?:vendor|supplier)\b|\b(?:new|become(?:\s+a)?)\s+(?:vendor|supplier)\b/i', 2, 'new_vendor'],
        ],
        'procurement_request' => [
            ['/\bpurchase\s+(?:order|request)\b/i', 2, 'purchase_order'],
            ['/\brequisition\b/i', 2, 'requisition'],
            ['/\bplace\s+an?\s+order\b|\braise\s+a\s+po\b|\bneed\s+to\s+(?:purchase|procure|buy)\b/i', 2, 'order_phrase'],
            ['/\bprocure(?:ment)?\b/i', 1, 'procure'],
            ['/\bpo\s*(?:#|no\.?|number)/i', 1, 'po_ref'],
        ],
        'quotation' => [
            ['/\bquot(?:e|es|ation|ations)\b/i', 2, 'quotation'],
            ['/\brfq\b|\bproposal\b|\brate\s+card\b|\bprice\s+list\b/i', 2, 'quote_terms'],
            ['/\bpricing\b|\bestimate\b/i', 1, 'pricing'],
        ],
        'follow_up' => [
            ['/\bfollow(?:ing)?[\s\-]*up\b/i', 2, 'follow_up'],
            ['/\b(?:gentle\s+)?reminder\b|\bchecking\s+in\b/i', 2, 'reminder'],
            ['/\bany\s+update\b|\bstatus\s+of\b|\bpending\s+since\b/i', 1, 'status_check'],
        ],
    ];
    $scores = ['vendor_onboarding' => 0, 'document_submission' => 0, 'procurement_request' => 0, 'quotation' => 0, 'follow_up' => 0];
    foreach ($rules as $type => $list) {
        foreach ($list as [$re, $w, $label]) {
            if (preg_match($re, $all) === 1) {
                $scores[$type] += $w;
                $kw[] = $label;
            }
        }
    }
    if ($scores['procurement_request'] > 0 && $extracted['amount'] !== null) {
        $scores['procurement_request'] += 1;
    }

    // document submission needs BOTH a document noun and a submission verb
    $nouns = [];
    if (preg_match_all('/\b(gst|tax\s+certificate|insurance|bank\s+verification|bank\s+proof|certificate|kyc)\b/i', $all, $nm)) {
        $nouns = array_unique(array_map('strtolower', $nm[1]));
    }
    $verbs = [];
    if (preg_match_all('/\b(attached|attachment|enclosed|please\s+find|submitting|submitted|uploaded|renewed|updated)\b/i', $all, $vm)) {
        $verbs = array_unique(array_map('strtolower', $vm[1]));
    }
    if ($nouns && $verbs) {
        $scores['document_submission'] = min(count($nouns), 3) + min(count($verbs), 2);
        $kw[] = 'document_submission';
    }

    arsort($scores);
    $types = array_keys($scores);
    $top = $scores[$types[0]];
    $second = $scores[$types[1]];
    $extracted['matched_keywords'] = array_values(array_unique($kw));

    if ($top < 2) {
        return ['request_type' => 'unknown', 'confidence' => round(min(0.35, 0.1 * $top), 2), 'extracted' => $extracted];
    }
    if ($top - $second < 1) {                                     // tie => ambiguous, do not guess
        $extracted['matched_keywords'][] = 'ambiguous_type';
        return ['request_type' => 'unknown', 'confidence' => round(min(0.35, 0.05 * $top), 2), 'extracted' => $extracted];
    }
    $conf = min(0.98, 0.4 + 0.12 * $top) - 0.1 * min($second, 3);
    return [
        'request_type' => $types[0],
        'confidence' => round(max(0.05, $conf), 2),
        'extracted' => $extracted,
    ];
}
