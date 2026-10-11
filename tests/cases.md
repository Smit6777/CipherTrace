# Novalite End-to-End Validation Cases

**Tester:** Professor
**Date:** 2026-10-10
**Environment:** localhost (XAMPP/Apache)

## Pre-Flight Checklist
- [x] Confirm Apache and MySQL/MariaDB are running in XAMPP.
- [x] Confirm PHP endpoints return JSON.
- [x] Confirm frontend pages are opened via `http://localhost/novalite/...`.
- [x] Confirm DB connection in `config.php` succeeds.
- [x] Confirm test tables exist: `vendors`, `vendor_documents`, `document_rules`, `workflow_cases`, `audit_log`, `mail_log`, `settings`, `contact_map`.
- [x] Confirm `settings` includes `follow_up_days`, `manual_review_owner`, `business_date`.
- [x] Confirm `document_rules` has active mandatory rules for GST certificate, tax certificate, insurance, bank verification.
- [x] Identified seeded vendor data:
  - Compliant: `accounts@apex-industrial.example`
  - Missing GST: `accounts@blueriver-logistics.example`
  - Blocked: `accounts@dunmore-traders.example`

## 1. Test Execution Plan & Exact Inputs

### T1-IDENTITY-001 (Real Sender Identity)
**Purpose:** Prove that the system stores the On-behalf-of identity as the real sender.
**Payload:**
```json
{
  "thread_id": "T1-IDENTITY-001",
  "from": "pack-mailbox@controlled.example",
  "subject": "Vendor onboarding request",
  "body": "On behalf of: Nova Supplies <nova@real.com>\nWe would like to begin vendor onboarding."
}
```

### T2-MISSING-GST-001 (Missing GST Onboarding Exception)
**Purpose:** Prove a vendor with missing GST enters Pending Documents and logs DOC-001.
**Payload:**
```json
{
  "thread_id": "T2-MISSING-GST-001",
  "from": "pack-mailbox@controlled.example",
  "subject": "Vendor onboarding documents",
  "body": "On behalf of: BlueRiver Logistics <accounts@blueriver-logistics.example>\nPlease process our vendor onboarding request."
}
```

### T3-ESCALATION-CRON (Follow-Up Deadline Escalation)
**Purpose:** Prove unresolved Pending Documents case escalates to a configured human owner.
**Actions:**
1. SQL: `UPDATE workflow_cases SET follow_up_due_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE case_ref = 'T2-MISSING-GST-001';`
2. Run: `php cron/followup.php`

### T4-AUTO-30000-001 (Low-Value Auto-Process)
**Purpose:** Prove a ₹30,000 procurement request from a fully compliant Active vendor is auto-processed and acknowledgement is logged.
**Payload:**
```json
{
  "thread_id": "T4-AUTO-30000-001",
  "from": "pack-mailbox@controlled.example",
  "subject": "Procurement request — office supplies",
  "body": "On behalf of: Apex Industrial Supplies <accounts@apex-industrial.example>\nProcurement request for office supplies. Amount: INR 30,000."
}
```

### T5-APPROVAL-325000-001 (High-Value Human Approval)
**Purpose:** Prove ₹3,25,000 request from compliant vendor enters Pending Approval and is never auto-approved.
**Payload:**
```json
{
  "thread_id": "T5-APPROVAL-325000-001",
  "from": "pack-mailbox@controlled.example",
  "subject": "High-value procurement request",
  "body": "On behalf of: Apex Industrial Supplies <accounts@apex-industrial.example>\nProcurement request. Amount: INR 3,25,000. Category: IT Hardware."
}
```

### T6-APPROVAL-ACTION (Human Approval API + Live UI Refresh)
**Purpose:** Prove `api/approve.php` correctly changes T5 case to approved after a valid matrix approver acts.
**Payload:**
```json
{
  "case_id": "NVL-104",
  "action": "APPROVE",
  "approver": "procurement.manager@novalite.example"
}
```

### T7-BLOCKED-10000-001 (Blocked Vendor Safety Override)
**Purpose:** Prove a ₹10,000 request from a Blocked vendor is never auto-approved despite being below the normal auto-processing threshold.
**Payload:**
```json
{
  "thread_id": "T7-BLOCKED-10000-001",
  "from": "pack-mailbox@controlled.example",
  "subject": "Procurement request",
  "body": "On behalf of: Dunmore Traders <accounts@dunmore-traders.example>\nProcurement request. Amount: INR 10,000."
}
```

### T8-CASE-LIST-UI (UI Search, Filter, Sort, Pagination)
**Purpose:** Prove the frontend correctly passes query parameters to `api/cases.php`, renders safe records without console errors, and handles responsive layout correctly.
**Actions:** Open `index.html`. Filter by "Pending Documents". Sort "Oldest first".

### T9-EVIDENCE-CARD-FORMAT (Evidence Card Format)
**Purpose:** Prove T2 Missing-GST case Evidence Card matches required product format.
**Actions:** Open `evidence.html?id=NVL-102`. Verify layout constraints.

### T10-SQL-INJECTION-SANITY (SQL Injection Sanity)
**Purpose:** Perform a controlled input-safety test across frontend/API inputs using `' OR 1=1 --`.
**Actions:** Input payload into Search, Intake form, Approval override payload, and Query strings.

---

## 2. Expected & Observed Evidence (Full Regression)

| Test | Scenario | Case ID | Expected | Actual | Audit Evidence | Mail Evidence | UI Evidence | Result |
|---|---|---|---|---|---|---|---|---|
| **T1** | Identity Parsing | `NVL-101` | Real sender successfully isolated via `On behalf of:` regex overriding inbox pack. | As expected. | 1 row created | - | Badge visible. | **PASS** |
| **T2** | Missing GST | `NVL-102` | `Pending Documents`, `DOC-001` flagged. | As expected. | 1 row `PENDING_DOCS` | 1 Chase Email | Amber Badge, Docs list shows 'Missing'. | **PASS** |
| **T3** | Cron Escalation | `NVL-102` | `Manual Review` trigger bypassing duplicates. | As expected. | 1 row `ESCALATED` | 1 Esc. Email | Red Badge, owner assigned. | **PASS** |
| **T4** | Low Value Auto | `NVL-103` | `<100,000` rule forces `Approved/Auto-processed`. | As expected. | 1 row `APP-001` | 1 Ack Email | Green Badge, `human_needed = No`. | **PASS** |
| **T5** | High Value Appr | `NVL-104` | `>300,000` rule forces `Pending Approval` capturing configured owner. | As expected. | 1 row `APP-003` | 1 Appr Email | Blue Badge, Action Panel mounts. | **PASS** |
| **T6** | UI Approval API | `NVL-104` | Approval validates user, state changes natively. | As expected. | 1 row `APPROVE` | - | Modal handles confirm. Fetch cleanly redraws Timeline. | **PASS** |
| **T7** | Blocked Vendor | `NVL-105` | Security override catches blocked status immediately avoiding auto-process. | As expected. | 1 row `MR-001` | 1 Esc. Email | Red Badge. | **PASS** |
| **T8** | Case List UI | - | Queries update dynamically; debouncing blocks rapid fire; mobile stacks layout. | As expected. | - | - | 0 Console errors, responsive cards trigger under 768px. | **PASS** |
| **T9** | Card Format | `NVL-102` | Sections correctly follow order [Decision, Why, Rule, Next Action, Human Needed, Audit]. | As expected. | - | - | `DOC-001` cleanly identified. DOM mounts dynamically with 0 `innerHTML`. | **PASS** |
| **T10** | SQLi Sanity | - | Endpoints safely return JSON formats/`400 Bad Request`. Prepared statements halt injection cleanly. | As expected. | - | - | Zero database dumps or unhandled `mysqli` stack traces exposed to client. | **PASS** |

