<?php
/**
 * engine/parse.php - real-sender identification.
 * Returns arrays / throws EngineException. Never prints JSON.
 */
declare(strict_types=1);

if (!class_exists('EngineException')) {
    class EngineException extends RuntimeException
    {
        public string $errorCode;

        public function __construct(string $message, string $errorCode = 'engine_error', ?Throwable $previous = null)
        {
            parent::__construct($message, 0, $previous);
            $this->errorCode = $errorCode;
        }
    }
}

/** First non-empty line of the body (UTF-8 BOM stripped), or null when the body is blank. */
function parse_first_non_empty_line(string $body): ?string
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $lines = preg_split('/\R/', $body);
    if ($lines === false) {
        return null;
    }
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t !== '') {
            return $t;
        }
    }
    return null;
}

/**
 * Identify the REAL business sender.
 *   1. FIRST non-empty line only:  On behalf of: Name <email>   -> source "on_behalf_of"
 *   2. No tag: a physical sender that is an active contact_map mailbox is REFUSED (tag required)
 *   3. No tag, external sender: -> source "physical_from_fallback"
 *
 * @return array{source:string,name:string,email:string}
 * @throws EngineException
 */
function extract_real_sender(mysqli $db, string $body, string $physicalFrom): array
{
    $first = parse_first_non_empty_line($body);

    if ($first !== null && preg_match('/On behalf of:\s*(?<name>.+?)\s*<(?<email>[^>]+)>/i', $first, $m) === 1) {
        $email = strtolower(trim($m['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new EngineException('The "On behalf of" tag does not contain a valid email address.', 'invalid_sender_email');
        }
        return ['source' => 'on_behalf_of', 'name' => trim($m['name']), 'email' => $email];
    }

    $physical = strtolower(trim($physicalFrom));
    if ($physical === '' || !filter_var($physical, FILTER_VALIDATE_EMAIL)) {
        throw new EngineException('No "On behalf of" tag found and the sending address is not a valid email.', 'invalid_physical_sender');
    }

    // A pack / stand-in mailbox can never be the business identity.
    $st = $db->prepare('SELECT 1 FROM contact_map WHERE active = 1 AND (pack_email = ? OR delivery_mailbox = ?) LIMIT 1');
    $st->bind_param('ss', $physical, $physical);
    $st->execute();
    $isPack = $st->get_result()->fetch_row() !== null;
    $st->close();

    if ($isPack) {
        throw new EngineException(
            'This message came from a shared pack mailbox. The first line must be "On behalf of: Name <email>" so the real sender can be identified.',
            'on_behalf_of_required'
        );
    }

    return ['source' => 'physical_from_fallback', 'name' => '', 'email' => $physical];
}
