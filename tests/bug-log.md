# Novalite Bug Log

**Tracking discovered issues across backend engine and frontend UI.**

| Bug ID | Test | Symptom | Pipeline Stage | Audit Present? | Root Cause | Fix Applied | Severity | Retest Result |
|---|---|---|---|---|---|---|---|---|
| BUG-001 | T2 | Missing GST vendor matched but no email sent initially. | Decision Engine | Yes | Seed data contact_map missed linking `accounts@blueriver-logistics.example` to the notification loop, or mail stub not triggered. | Fixed seed.sql map. | Medium | PASS |
| BUG-002 | T3 | Running cron twice outputs duplicate escalation logs for the same case. | Cron Follow-Up | Yes (Duplicates) | Query didn't exclude cases already escalated or `chase_count` wasn't capped properly. | Patched `cron/followup.php` idempotency check. | High | PASS |
| BUG-003 | T4 | Low-value case marked Manual Review instead of Auto-processed. | Decision Engine | Yes | Matrix rule amounts were evaluated as strings causing "30000" > "100000" logic failure. | Enforced type casting `(float)` on amount parser. | High | PASS |
| BUG-004 | T6 | Modal confirmation triggers duplicate POST if clicked repeatedly rapidly. | UI (`evidence.js`) | No | Missing immediate `disabled=true` state binding prior to network resolution. | Added `setButtonLoading()` disabling logic on click. | Medium | PASS |
| BUG-005 | T8 | Pagination total page calculation misaligned on list view if divisible equally by 20. | UI (`cases.js`) | No | Math.ceil logic mismatch resolving +1 blank page. | Patched standard pagination calculation bounds in cases list. | Low | PASS |
