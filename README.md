# CipherTrace — by Team CipherSight

**CipherTrace** is an explainable AI procurement assistant. It parses messy, forwarded procurement emails, works out **who really sent them**, verifies vendor compliance rules in MySQL, and routes every case into one of **four deterministic outcomes**, backed by an immutable audit trail and a plain-English Evidence Card.

> *"Decode the sender. Trace the decision. Secure the spend."*

| Outcome (stored `current_status`) | Shown in API as | Meaning |
|---|---|---|
| `Approved` | `Approved/Auto-processed` | Vendor compliant and amount inside an auto-process band (or a human approved it) |
| `Pending Documents` | `Pending Documents` | A mandatory document is missing / awaiting verification; the vendor is chased by cron |
| `Pending Approval` | `Pending Approval` | Amount band needs a named approver |
| `Manual Review` | `Manual Review` | Blocked vendor, expired document, failed bank check, unknown sender, low confidence, no safe request match, or overdue documents |

A fifth status, `Rejected`, is set only when an authorised approver calls `api/approve.php` with `REJECT`.

**Identity rule.** The physical `from` address is *not* the vendor. The first non-empty line of the body, `On behalf of: Name <email>`, establishes the real identity. A message from a shared pack mailbox (any active `contact_map` address) without that line is refused with `on_behalf_of_required`.

## Setup (XAMPP)

* PHP **8.1+** (8.3 tested) with `mysqli` and `mbstring` enabled. MariaDB 10.4+ (XAMPP) or MySQL 8.
* Copy the folder to `C:\xampp\htdocs\novalite` (macOS/Linux: `/opt/lampp/htdocs/novalite`). Start **Apache** and **MySQL** in the XAMPP control panel.
* Import the schema (creates the `novalite` database, tables and seed data; safe to re-run):
  ```
  mysql -u root -p < sql/schema.sql
  ```
  or phpMyAdmin → *Import* → choose `sql/schema.sql`.
* `config.php` holds connection settings only. Defaults match XAMPP (`127.0.0.1:3306`, user `root`, empty password, DB `novalite`). Override with the environment variables `NOVALITE_DB_HOST`, `NOVALITE_DB_PORT`, `NOVALITE_DB_NAME`, `NOVALITE_DB_USER`, `NOVALITE_DB_PASS`.
* Base URL used below: `http://localhost/novalite`.

**Upgrading an existing Part 1 database.** Fresh installs need nothing extra. For an existing database run, on MariaDB/XAMPP:
```sql
ALTER TABLE workflow_cases ADD COLUMN IF NOT EXISTS follow_up_due_at DATE NULL AFTER owner;
ALTER TABLE workflow_cases ADD COLUMN IF NOT EXISTS chase_count INT NOT NULL DEFAULT 0 AFTER follow_up_due_at;
ALTER TABLE workflow_cases ADD KEY ix_case_followup (current_status, follow_up_due_at);
```
(on MySQL remove `IF NOT EXISTS` and run once), then re-run `sql/schema.sql` to add `config_audit_log` and the `max_follow_up_chases` setting.

## Schema overview

| Table | Purpose |
|---|---|
| `settings` | All tunables: `follow_up_days`, `max_follow_up_chases`, `manual_review_owner`, `system_from_email`, `case_prefix`, `case_next_number`, `business_date`, `classification_confidence_threshold`, `hard_stop_vendor_statuses`, `open_request_statuses` |
| `contact_map` | Persona addresses (`pack_email`) and the stand-in mailbox they arrive at (`delivery_mailbox`); used to refuse pack mailboxes as business identity |
| `vendors` | Vendor master: status, risk level, bank verification |
| `document_rules` | **Which documents are mandatory** (type, mandatory/active, scope, order). The engine reads this on every decision, so adding a mandatory document needs no code change |
| `vendor_documents` | One current row per vendor and document type: verification status, expiry |
| `approval_matrix` | Amount bands × risk × category → approver(s) and whether approval is required |
| `procurement_requests` | Requests that e-mails are matched to |
| `workflow_cases` | One row per e-mail thread: status, owner, next action, `follow_up_due_at`, `chase_count` |
| `audit_log` | Append-only decision history with JSON evidence (one row per state change) |
| `mail_log` | **Every outbound mail.** SMTP is simulated: nothing is sent, the message is recorded here, so the demo is verifiable and has no external dependency |
| `config_audit_log` | Old/new values for each approval-matrix edit (kept apart from `audit_log`, which needs a case) |

## Response conventions

Success responses are the documented JSON objects. Errors always use:
```json
{"ok": false, "error": {"code": "validation_error", "message": "'thread_id' is required."}}
```
`400` validation · `403` approver not authorised · `404` not found · `405` wrong method · `409` state conflict · `500` safe server error (details go to the PHP error log only).

## API

### 1. `POST /api/intake.php`
Body: `thread_id` (required, ≤100, `A-Za-z0-9._:@-`), `from` (required, valid e-mail), `body` (required), `subject` (optional).
Creates one case and decides it in **one transaction** (one audit row, one mail_log row). A repeated `thread_id` returns the existing case with `200` and `"duplicate": true`; nothing new is written.
```bash
curl -s -X POST http://localhost/novalite/api/intake.php -H 'Content-Type: application/json' -d '{
 "thread_id":"T-1","from":"physical-sender@example.com",
 "subject":"Purchase order for office supplies INR 32,500",
 "body":"On behalf of: Apex Accounts <accounts@apex-industrial.example>\nPlease raise a purchase order for office supplies, INR 32,500. Category: Office Supplies"}'
```
`201`:
```json
{"case_id":"VP-1001","real_sender":{"name":"Apex Accounts","email":"accounts@apex-industrial.example","source":"on_behalf_of"},
 "request_type":"procurement_request","status":"Approved/Auto-processed",
 "next_action":"Auto-processed under rule 1; acknowledgement logged to accounts@apex-industrial.example.",
 "owner":"procurement.bot@novalite.example","duplicate":false}
```
Error (`400`): `{"ok":false,"error":{"code":"on_behalf_of_required","message":"This message came from a shared pack mailbox. ..."}}`.
On a duplicate, `real_sender.name` is `""` and `source` is `"stored"` (the name is not persisted).

### 2. `POST /api/approve.php`
Body: `action` (exactly `APPROVE` or `REJECT`), `approver` (e-mail), and `case_id` **or** `thread_id` (approval-reply threading; never creates a case).
The case must be `Pending Approval`. The approver is checked against the `approver_email` list of the approval-matrix rule that applies to the case's request amount, vendor risk and category; the client's claim is not trusted.
```bash
curl -s -X POST http://localhost/novalite/api/approve.php -H 'Content-Type: application/json' \
 -d '{"thread_id":"T-2","action":"APPROVE","approver":"procurement.manager@novalite.example"}'
```
`200`:
```json
{"case_id":"VP-1002","status":"Approved/Auto-processed","decision":"APPROVE","owner":"procurement.bot@novalite.example","message":"Case VP-1002 approved by procurement.manager@novalite.example."}
```
REJECT sets status `Rejected`. Each call writes one `audit_log` row (action `APPROVED`/`REJECTED`, actor = the approver) and one `mail_log` row (`acknowledgement` / `rejection_notice`).
Errors: `403` `{"code":"forbidden","message":"finance.head@... is not an authorised approver for rule 2."}`, `409` `{"code":"conflict","message":"Case VP-1002 is 'Approved', not 'Pending Approval'."}`, `404` unknown case.
**Multiple approvers:** when a rule lists several (rule 3), *any one* listed approver can decide. Requiring all of them would need a per-approver table; see limitations.

### 3. `GET /api/cases.php?search=&status=&sort=&page=`
20 rows per page. `search` matches `case_id`, `real_sender_email`, `vendor_name`, `vendor_email`. `status` must be one of `New, Approved, Approved/Auto-processed, Pending Documents, Pending Approval, Manual Review, Rejected`. `sort` is `asc` or `desc` (default `desc`, on `created_at`).
```bash
curl -s 'http://localhost/novalite/api/cases.php?search=apex&status=Approved&sort=asc&page=1'
```
```json
{"rows":[{"case_id":"VP-1001","thread_id":"T-1","real_sender_email":"accounts@apex-industrial.example","request_type":"procurement_request","current_status":"Approved","status":"Approved/Auto-processed","next_action":"...","owner":"procurement.bot@novalite.example","follow_up_due_at":null,"created_at":"2026-10-10 17:11:04","vendor_name":"Apex Industrial Supplies","vendor_email":"accounts@apex-industrial.example"}],
 "total":2,"page":1,"perPage":20,"totalPages":1}
```
Error: `?sort=desc;DROP` → `400 validation_error`.

### 4. `GET /api/case.php?id=VP-1005`
Returns `evidence_card`, `case`, `vendor`, `procurement_request` (`{}` when not linked), `documents`, `audit_trail` (oldest first, evidence decoded) and `mail_history`.
```bash
curl -s 'http://localhost/novalite/api/case.php?id=VP-1005'
```
Excerpt: `{"evidence_card":{"case_id":"VP-1005","status":"Rejected","decision":"Rejected","rule_applied":"AM-3 - amount band ₹200,000.01 to no upper limit, approval required",...},"audit_trail":[{"action":"PENDING_APPROVAL",...},{"action":"REJECTED","actor":"procurement.head@novalite.example",...}],...}`
Error: `404 case_not_found` for an unknown id; `400` for a malformed id.

### 5. `GET` / `POST /api/matrix.php`
`GET` returns `approval_matrix`, `document_rules`, `settings` and a `non_overridable` note.
`POST` updates **existing** rules (partial updates allowed). Every row that actually changes writes one `config_audit_log` row; the whole request is one transaction.
```bash
curl -s -X POST http://localhost/novalite/api/matrix.php -H 'Content-Type: application/json' -d '{
 "changed_by":"admin@novalite.example",
 "rules":[{"rule_id":2,"approver_email":"procurement.manager@novalite.example,deputy.manager@novalite.example"}]}'
```
`200`: `{"updated":[2],"unchanged":[],"approval_matrix":[...]}`.
Validation: `min_amount >= 0`; `max_amount` null or `>= min_amount`; `risk_level` ∈ `Any|Low|Medium|High`; `category` = `Any` or a short name; `approval_required` is `0`/`1`; every address in `approver_email` must be valid and the list is required when `approval_required = 1`; unknown fields are rejected. A change that would leave an amount range with no rule (a gap or no open-ended top band among `Any`-risk rules) is refused with `409` and rolled back.
**Non-overridable:** Blocked vendors (MR-001) and expired mandatory documents (MR-002) are decided by the engine *before* the matrix is consulted. No matrix field can change that.

### 6. `POST /api/webhook/classify-test.php`
Parse and classify only; creates no case and writes nothing.
```bash
curl -s -X POST http://localhost/novalite/api/webhook/classify-test.php -H 'Content-Type: application/json' -d '{
 "from":"test@example.com","subject":"Quote for office supplies INR 75,000",
 "body":"On behalf of: Test Vendor <vendor@example.com>\nPlease find quotation..."}'
```
```json
{"real_sender":{"name":"Test Vendor","email":"vendor@example.com","source":"on_behalf_of"},
 "classification":{"request_type":"quotation","confidence":0.64,"extracted":{"request_id":null,"vendor_id":null,"amount":75000,"category":null,"approval_action":null,"matched_keywords":["amount","quotation"]}}}
```

## Example scenarios (seed data)

| Scenario | `thread_id` / body essentials | Result |
|---|---|---|
| Valid compliant request | Apex, `INR 32,500`, `Category: Office Supplies` | `Approved/Auto-processed` (rule 1) |
| Manager approval | Apex, `INR 125000`, `Category: IT Hardware` | `Pending Approval` → owner `procurement.manager@...` |
| Missing GST vendor | BlueRiver `accounts@blueriver-logistics.example`, `INR 45000`, `Category: Logistics Services` | `Pending Documents`, `follow_up_due_at` = business date + `follow_up_days` |
| Blocked vendor | Dunmore `accounts@dunmore-traders.example`, `INR 78000`, `Category: Raw Materials` | `Manual Review`, rule `MR-001` |
| High-value approval | Apex, `INR 650000`, `Category: Capital Equipment` | `Pending Approval` → finance head / procurement head (rule 3) |
| Approval action | `POST api/approve.php` with the thread or case id | `Approved` / `Rejected` |
| Case search | `GET api/cases.php?search=apex` | paged rows |
| Evidence Card | `GET api/case.php?id=VP-1002` | card + raw data |
| Classifier test | `POST api/webhook/classify-test.php` | parse + classification |

A request message must carry the amount and category that match one open (`status = New`) seed request for that vendor; otherwise the engine does not guess and the case goes to Manual Review (`MR-007`). Each seed request can therefore be consumed once. Re-import the schema into a fresh database to reset the demo.

## Cron (follow-ups)

Run manually (CLI only; over HTTP it returns 403):
```
php cron/followup.php
```
Every 15 minutes:
```
*/15 * * * * /path/to/php /path/to/novalite/cron/followup.php >> /path/to/novalite/cron/followup.log 2>&1
```
The folder `cron/` must be **writable** by the cron user so the log file can be created. On Windows use Task Scheduler with `C:\xampp\php\php.exe C:\xampp\htdocs\novalite\cron\followup.php`.

Behaviour for cases in `Pending Documents` whose `follow_up_due_at <= today` (structured column, never parsed from text):

1. Documents now complete (or one expired): the engine re-decides (`decide_case`), producing one decision audit row.
2. Fewer than `settings.max_follow_up_chases` chases sent: a `follow_up_chase` mail is logged, `chase_count` +1, `follow_up_due_at` moves to today + `settings.follow_up_days` (so the next 15-minute run does nothing), and one `FOLLOW_UP_CHASE` audit row (`FU-001`) is written.
3. Chases exhausted and the date has passed: status → `Manual Review`, owner = `settings.manual_review_owner`, an escalation mail is logged and one `FOLLOW_UP_ESCALATED` audit row (`FU-002`) is written.

"Today" is `settings.business_date`; to demo the timeline, edit that setting (delete the row to use the database date). A MySQL advisory lock prevents overlapping runs. Output is one concise line per case plus a summary; secrets are never printed.

## Test checklist

Run `tests/smoke.sh` (needs `curl`, `mysql`, a running server) or tick these by hand:

- [ ] Exactly one `audit_log` row per decision branch (auto-approve, pending documents, pending approval, manual review); an approval/rejection adds exactly one more
- [ ] No Google services or local-disk files are used anywhere
- [ ] No hard-coded thresholds, approvers, deadlines or vendors: bands from `approval_matrix`, days/owners from `settings`, mandatory documents from `document_rules`
- [ ] Every SQL statement is prepared (`grep` the code; the only interpolated fragments are literals chosen from allowlists)
- [ ] A duplicate `thread_id` returns the same case with `200` and creates nothing
- [ ] Real-sender parsing: `On behalf of:` wins; a pack mailbox without it is refused
- [ ] A Blocked vendor is never auto-approved
- [ ] An expired mandatory document is never auto-approved
- [ ] Changing a row in `approval_matrix`, `document_rules` or `settings` changes behaviour with no code change

## Security limitations (hackathon prototype)

* **No authentication or authorisation on any endpoint** (including `matrix.php`, which changes business rules). `approve.php` checks the approver's e-mail against the matrix, but nothing proves the caller *is* that person.
* SMTP is simulated through `mail_log`; nothing is sent. Rule 3 stores both approvers in one `recipient_email` value.
* Multi-approver rules accept any single listed approver.
* Production would need: secrets in environment variables, authentication, RBAC, CSRF/CORS policy, webhook signature verification, encrypted credentials, rate limiting, stronger input validation, database-level append-only enforcement for `audit_log`, and real mail delivery.
