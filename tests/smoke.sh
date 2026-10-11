#!/usr/bin/env bash
# Smoke test for a FRESH database (import sql/schema.sql first). Needs: curl, python3, mysql client.
#   BASE=http://localhost/novalite  MYSQL="mysql -uroot novalite -N" bash tests/smoke.sh
BASE="${BASE:-http://127.0.0.1:8080}"; MYSQL="${MYSQL:-mysql -uroot novalite -N}"
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS $1"; else fail=$((fail+1)); echo "FAIL $1 (expected '$3' got '$2')"; fi; }
post(){ curl -s -o /tmp/nv_out -w "%{http_code}" -H 'Content-Type: application/json' -d "$2" "$BASE/api/$1"; }
jq_(){ python3 -c "import sys,json; d=json.load(open('/tmp/nv_out')); print($1)"; }
sql(){ $MYSQL -e "$1"; }
intake(){ post intake.php "{\"thread_id\":\"$1\",\"from\":\"p@example.com\",\"subject\":\"$2\",\"body\":\"On behalf of: V <$3>\\n$4\"}"; }

ok "compliant -> 201" "$(intake S-1 'Purchase order INR 32,500' accounts@apex-industrial.example 'Purchase order office supplies INR 32,500 Category: Office Supplies')" 201
ok "compliant status" "$(jq_ "d['status']")" "Approved/Auto-processed"
C1=$(jq_ "d['case_id']")
ok "duplicate thread -> 200" "$(intake S-1 'x' accounts@apex-industrial.example 'hello')" 200
ok "duplicate flagged" "$(jq_ "d['duplicate']")" "True"
ok "duplicate made no 2nd case" "$(sql "select count(*) from workflow_cases where thread_id='S-1'")" 1
ok "one audit row (auto)" "$(sql "select count(*) from audit_log where case_id='$C1'")" 1

ok "manager band -> 201" "$(intake S-2 'Purchase order INR 125000' accounts@apex-industrial.example 'Purchase order IT hardware INR 125000 Category: IT Hardware')" 201
ok "manager band status" "$(jq_ "d['status']")" "Pending Approval"
C2=$(jq_ "d['case_id']")
ok "high value -> 201" "$(intake S-3 'Purchase order INR 650000' accounts@apex-industrial.example 'Purchase order capital equipment INR 650000 Category: Capital Equipment')" 201
C3=$(jq_ "d['case_id']")
ok "missing GST -> Pending Documents" "$(intake S-4 'Purchase order INR 45000' accounts@blueriver-logistics.example 'Purchase order logistics services INR 45000 Category: Logistics Services' >/dev/null; jq_ "d['status']")" "Pending Documents"
C4=$(jq_ "d['case_id']")
ok "due date stored" "$([ -n "$(sql "select follow_up_due_at from workflow_cases where case_id='$C4' and follow_up_due_at is not null")" ] && echo yes)" yes
intake S-5 'Purchase order INR 78000' accounts@dunmore-traders.example 'Purchase order raw materials INR 78000 Category: Raw Materials' >/dev/null
ok "blocked -> Manual Review" "$(jq_ "d['status']")" "Manual Review"
ok "blocked rule MR-001" "$(sql "select rule_id from audit_log where case_id='$(jq_ "d['case_id']")'")" "MR-001"
ok "every case has exactly 1 audit so far" "$(sql "select count(*) from (select case_id, count(*) n from audit_log group by case_id having n<>1) t")" 0

ok "pack mailbox w/o tag -> 400" "$(post intake.php '{"thread_id":"S-6","from":"pack-inbox-1@novalite.test","body":"hello"}')" 400
ok "missing thread_id -> 400" "$(post intake.php '{"from":"a@b.co","body":"x"}')" 400
ok "GET intake -> 405" "$(curl -s -o /dev/null -w '%{http_code}' $BASE/api/intake.php)" 405

ok "wrong approver -> 403" "$(post approve.php "{\"case_id\":\"$C2\",\"action\":\"APPROVE\",\"approver\":\"finance.head@novalite.example\"}")" 403
ok "bad action -> 400" "$(post approve.php "{\"case_id\":\"$C2\",\"action\":\"maybe\",\"approver\":\"procurement.manager@novalite.example\"}")" 400
ok "approve by thread -> 200" "$(post approve.php '{"thread_id":"S-2","action":"APPROVE","approver":"procurement.manager@novalite.example"}')" 200
ok "approve made no new case" "$(sql "select count(*) from workflow_cases")" 5
ok "approve audits: 2 rows" "$(sql "select count(*) from audit_log where case_id='$C2'")" 2
ok "approve again -> 409" "$(post approve.php "{\"case_id\":\"$C2\",\"action\":\"APPROVE\",\"approver\":\"procurement.manager@novalite.example\"}")" 409
ok "reject -> 200" "$(post approve.php "{\"case_id\":\"$C3\",\"action\":\"REJECT\",\"approver\":\"procurement.head@novalite.example\"}")" 200
ok "rejected status" "$(sql "select current_status from workflow_cases where case_id='$C3'")" Rejected
ok "not-pending approve -> 409" "$(post approve.php "{\"case_id\":\"$C1\",\"action\":\"APPROVE\",\"approver\":\"procurement.manager@novalite.example\"}")" 409
ok "unknown case -> 404" "$(post approve.php '{"case_id":"VP-9999","action":"APPROVE","approver":"a@b.co"}')" 404

ok "cases search 200" "$(curl -s -o /tmp/nv_out -w '%{http_code}' "$BASE/api/cases.php?search=apex&sort=asc")" 200
ok "cases total 3" "$(jq_ "d['total']")" 3
ok "bad sort -> 400" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/cases.php?sort=x")" 400
ok "case 200" "$(curl -s -o /tmp/nv_out -w '%{http_code}' "$BASE/api/case.php?id=$C3")" 200
ok "case audit chronological" "$(jq_ "[a['action'] for a in d['audit_trail']]")" "['PENDING_APPROVAL', 'REJECTED']"
ok "case unknown -> 404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/case.php?id=VP-9999")" 404

ok "matrix update -> 200" "$(post matrix.php '{"rules":[{"rule_id":2,"approver_email":"procurement.manager@novalite.example,deputy@novalite.example"}]}')" 200
ok "matrix config audit" "$(sql "select count(*) from config_audit_log")" 1
ok "matrix gap -> 409" "$(post matrix.php '{"rules":[{"rule_id":2,"min_amount":60000}]}')" 409
ok "matrix max<min -> 400" "$(post matrix.php '{"rules":[{"rule_id":2,"min_amount":500,"max_amount":100}]}')" 400
ok "matrix rolled back" "$(sql "select min_amount from approval_matrix where rule_id=2")" 50000.01

ok "classify-test 200" "$(post webhook/classify-test.php '{"from":"t@example.com","subject":"Quote INR 75,000","body":"On behalf of: T <v@example.com>\nPlease find quotation"}')" 200
ok "classify-test created no case" "$(sql "select count(*) from workflow_cases")" 5

echo "--- cron lifecycle"; setd(){ $MYSQL -e "update settings set value='$1' where key_name='business_date'"; }
D0=$(sql "select value from settings where key_name='business_date'")
setd 2026-10-13; php "$(dirname "$0")/../cron/followup.php" >/dev/null
ok "cron chase 1" "$(sql "select chase_count from workflow_cases where case_id='$C4'")" 1
php "$(dirname "$0")/../cron/followup.php" >/dev/null
ok "cron no repeat same day" "$(sql "select count(*) from audit_log where case_id='$C4' and action='FOLLOW_UP_CHASE'")" 1
setd 2026-10-16; php "$(dirname "$0")/../cron/followup.php" >/dev/null
setd 2026-10-19; php "$(dirname "$0")/../cron/followup.php" >/dev/null
ok "cron escalated" "$(sql "select current_status from workflow_cases where case_id='$C4'")" "Manual Review"
ok "cron escalation audit once" "$(sql "select count(*) from audit_log where case_id='$C4' and action='FOLLOW_UP_ESCALATED'")" 1
setd "$D0"
echo "passed=$pass failed=$fail"; [ "$fail" -eq 0 ]
