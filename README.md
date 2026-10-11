# CipherTrace

> **Autonomous Explainable AI Procurement & Vendor Onboarding Assistant**  
> Built by **Team CipherSight** for **Autopilot Ahmedabad (Domain B: Statement B1)**  
> **Live Supervity Auto AI Employee:** [View Agent on auto.supervity.ai](https://auto.supervity.ai/u/alpha/agent/01a12a38-cb36-71c6-ba98-566fe5e0ca67)  
> **GitHub Repository:** [https://github.com/Smit6777/CipherTrace](https://github.com/Smit6777/CipherTrace)

---

## 📌 Executive Summary

**CipherTrace** is an enterprise-grade AI procurement assistant engineered to eliminate the manual bottleneck in corporate vendor onboarding and purchase requisition governance. 

Real-world enterprise emails arrive from shared or forwarded mailboxes with messy headers, missing mandatory tax certificates, unverified bank details, and compliance anomalies. **CipherTrace** autonomously:
1. **Resolves Real Identities:** Extracts genuine business senders from forwarded `On behalf of: Name <email>` headers.
2. **Validates Mandatory Compliance:** Cross-references mandatory vendor documents (GST Certificate, Tax Exemption, Insurance, Bank Verification) against active enterprise policies.
3. **Automates Exception Handling:** Automatically schedules document chase cycles and deadlines for missing files without human intervention.
4. **Enforces Hard-Stop Safety Stops:** Strictly quarantines blacklisted or failed-verification suppliers from automated execution.
5. **Governs Tiered Spend Policies:** Evaluates purchase requisitions against configurable spending matrices, auto-clearing low-risk routine spend while routing high-stakes approvals to authorized leaders via interactive **Evidence Cards**.
6. **Maintains Tamper-Evident Audit Trails:** Commits immutable actor, rule, and JSON evidence snapshots to relational storage.

---

## 🎯 Outcomes & State Machine

Every inbound email processed through CipherTrace is deterministically assigned to exactly **one of four operational states**:

| State | Display Label | Operational Semantics | Action Taken |
| :--- | :--- | :--- | :--- |
| **`Approved`** | `Approved/Auto-processed` | Compliant vendor & order value within auto-approval threshold (or authorized by human leader). | Immediate PO confirmation logged to vendor. |
| **`Pending Documents`** | `Pending Documents` | Mandatory legal document (e.g., GST Certificate) missing or awaiting verification. | Status updated, 3-day chase cycle scheduled, request email dispatched. |
| **`Pending Approval`** | `Pending Approval` | Order value exceeds auto-process ceiling and requires designated managerial sign-off. | Evidence Card generated; dispatched to Procurement Lead or Finance Director. |
| **`Manual Review`** | `Manual Review` | Blocked vendor, expired documents, failed bank verification, low confidence, or unresolvable conflict. | Safety stop engaged; quarantined to human compliance officer. |

*(A terminal state `Rejected` is recorded whenever an authorized approver declines a requisition via the Evidence Card).*

---

## 🏗️ Architecture & Integrations

```
[Inbound Email / Outlook] 
          │
          ▼
[Supervity Auto AI Employee] ──► [On behalf of: Extraction]
          │
          ├── (≤ ₹50,000 Compliant) ────────► [Auto-Approve Tier 1 & Log]
          ├── (Missing GST / Docs) ──────────► [Document Chase & Schedule Deadline]
          ├── (Blocked Supplier / High Risk) ─► [Manual Review Quarantined Safety Stop]
          └── (High-Value > ₹50,000) ────────► [Evidence Card ➔ Human-in-the-Loop Sign-off]
                                                        │
                                                        ▼
                                            [MySQL / MariaDB Audit Trail]
```

### Connected Systems (Section 4 Compliance)
* **Channel Integration:** Microsoft Outlook (`auto.supervity.ai` live event trigger on incoming message).
* **System of Record:** MariaDB / MySQL Relational Database (10 tables, strict foreign keys, parameterized queries).
* **AI Orchestrator:** Supervity Auto ([Agent 01a12a38-cb36-71c6-ba98-566fe5e0ca67](https://auto.supervity.ai/u/alpha/agent/01a12a38-cb36-71c6-ba98-566fe5e0ca67)).
* **Local Web Server:** Apache HTTP Server (`http://localhost/novalite/`).

---

## ⚙️ Spend Matrix Governance

Configured dynamically in database table `approval_matrix`:

| Rule ID | Amount Band | Risk Level | Category | Approver Email | Action Required |
| :---: | :---: | :---: | :---: | :---: | :---: |
| **AM-1** | ₹0 – ₹50,000 | Low / Any | Any | *None* | `Auto-processed` (Zero human touch) |
| **AM-2** | ₹50,000.01 – ₹2,00,000 | Any | Any | `procurement.head@novalite.example` | Procurement Lead Sign-off |
| **AM-3** | ₹200,000.01 – No Limit | Any | Any | `finance.head@novalite.example` | Finance Director Executive Sign-off |

---

## 📂 Web Applications & Endpoints

### Operational Web Interfaces
* **Overview Dashboard:** [`index.html`](index.html) — Executive operational KPIs, active approval queue, and compliance watchlist.
* **Workflow Cases:** [`cases.html`](cases.html) — Case directory with debounced search, status pills, and pagination.
* **Evidence Cards:** [`evidence.html`](evidence.html) — Explainable 6-section decision breakdown with interactive human authorization controls.
* **Approval Matrix:** [`settings.html`](settings.html) — Live spend threshold policy editor.
* **Vendors & Compliance:** [`vendors.html`](vendors.html) — Supplier directory with GST validation and document health indicators.
* **Audit Trail:** [`audit.html`](audit.html) — Immutable ledger of all system and human decisions.
* **Testing Lab:** [`test.html`](test.html) — Interactive payload simulation console with 3 one-click presets.

### REST API Endpoints
1. `POST /api/intake.php`: Ingests inbound email payloads, allocates case IDs (`VP-1001`), executes decision precedence, and commits audit logs in a single transaction.
2. `POST /api/approve.php`: Authorizes or rejects pending high-value cases with approver authorization validation.
3. `GET /api/cases.php`: Paginated search, sorting, and filtering of all workflow cases.
4. `GET /api/case.php?id=VP-XXXX`: Delivers the complete Evidence Card and raw verification telemetry.
5. `GET /api/overview.php`: Powers real-time dashboard stat tiles and follow-up queues.
6. `GET /api/vendors.php`: Queries registered vendors and active document compliance matrices.
7. `GET /api/audit.php`: Provides chronological, verifiable audit logs.
8. `GET / POST /api/matrix.php`: Dynamic retrieval and atomic modification of approval matrix bands.

---

## 🧪 Validation Scenarios (Round 1 Test Fixtures)

CipherTrace is pre-configured and tested against standard enterprise edge cases:

| Scenario | Input Subject & Payload | Triggered Rule | Resulting State & Action |
| :--- | :--- | :---: | :--- |
| **1. Missing GST Onboarding** | BlueRiver Logistics onboarding missing GST Certificate. | `DOC-001` | **`Pending Documents`** — Follow-up chase scheduled for 3 days; outbound email logged. |
| **2. High-Value Order** | Apex Industrial Supplies order for ₹3,25,000 (IT Hardware). | `AM-3` | **`Pending Approval`** — Routed to Finance Director via Evidence Card for human authorization. |
| **3. Blocked Supplier Safety Stop** | Dunmore Traders (Failed bank check / Blocked status). | `MR-001` | **`Manual Review`** — Autonomous approval strictly blocked; flagged for compliance review. |
| **4. Fast-Path Auto Approval** | Apex Industrial Supplies routine order for ₹32,500. | `AM-1` | **`Approved/Auto-processed`** — Instantly verified and confirmed without human touch. |

---

## 🚀 Local Setup & Installation

### Requirements
* PHP 8.1+ (Tested on PHP 8.2+) with `mysqli` and `mbstring` extensions enabled.
* MariaDB 10.4+ or MySQL 8.0+.
* Apache HTTP Server (e.g. XAMPP standard stack).

### Quickstart
1. Clone or copy the repository to your Apache web root:
   ```bash
   git clone https://github.com/Smit6777/CipherTrace.git
   ```
2. Start **Apache** and **MySQL** services.
3. Import the database schema and seed records:
   ```bash
   mysql -u root -p < sql/schema.sql
   ```
4. Access the web interface in your browser:
   ```text
   http://localhost/novalite/index.html
   ```

---

## 🛡️ Hackathon Submission Details

* **Hackathon:** Autopilot Ahmedabad (Round 1)
* **Domain:** Domain B — Vendor and Procurement Lifecycle
* **Problem Statement:** B1 — Vendor Onboarding & Procurement Lifecycle
* **Team:** CipherSight
* **Team Lead:** Smit Makwana (`smitmakwana6777@gmail.com`)
* **Supervity Workspace:** [https://auto.supervity.ai/u/alpha/agent/01a12a38-cb36-71c6-ba98-566fe5e0ca67](https://auto.supervity.ai/u/alpha/agent/01a12a38-cb36-71c6-ba98-566fe5e0ca67)
* **GitHub Repository:** [https://github.com/Smit6777/CipherTrace](https://github.com/Smit6777/CipherTrace)
