-- Novalite schema + seed data.  Target: MariaDB 10.4+ (XAMPP) / MySQL 8.
-- Re-runnable: CREATE ... IF NOT EXISTS and INSERT IGNORE; never drops data.
-- NOTE: Part 1 tables are reconstructed from the Part 1 summary. Part 3 additions are marked [PART 3].
CREATE DATABASE IF NOT EXISTS novalite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE novalite;

CREATE TABLE IF NOT EXISTS settings (
  key_name   VARCHAR(64)  NOT NULL PRIMARY KEY,
  `value`    VARCHAR(500) NOT NULL,
  description VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contact_map (
  contact_id       INT AUTO_INCREMENT PRIMARY KEY,
  persona_name     VARCHAR(120) NOT NULL,
  role_title       VARCHAR(120) NULL,
  contact_type     ENUM('staff','vendor') NOT NULL DEFAULT 'staff',
  pack_email       VARCHAR(190) NOT NULL UNIQUE,   -- persona address used in the business flow
  delivery_mailbox VARCHAR(190) NOT NULL,          -- stand-in mailbox where it physically arrives
  active           TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendors (
  vendor_id   INT AUTO_INCREMENT PRIMARY KEY,
  vendor_name VARCHAR(190) NOT NULL,
  vendor_email VARCHAR(190) NOT NULL UNIQUE,
  gst_id      VARCHAR(30) NULL,
  vendor_status ENUM('Active','Pending Documents','Blocked','Manual Review') NOT NULL DEFAULT 'Pending Documents',
  risk_level  ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  bank_verification_status ENUM('Pending','Verified','Failed') NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_rules (
  document_type VARCHAR(60) NOT NULL PRIMARY KEY,
  is_mandatory  TINYINT(1) NOT NULL DEFAULT 1,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  scope         VARCHAR(40) NOT NULL DEFAULT 'ALL_VENDORS',
  sort_order    INT NOT NULL DEFAULT 0,
  description   VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendor_documents (
  doc_id  INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  document_type VARCHAR(60) NOT NULL,
  verification_status ENUM('Verified','Pending','Expired','Missing') NOT NULL DEFAULT 'Missing',
  expiry_date DATE NULL,
  UNIQUE KEY uq_vendor_doc (vendor_id, document_type),
  CONSTRAINT fk_vd_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE RESTRICT,
  CONSTRAINT fk_vd_rule   FOREIGN KEY (document_type) REFERENCES document_rules(document_type) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS approval_matrix (
  rule_id    INT AUTO_INCREMENT PRIMARY KEY,
  min_amount DECIMAL(12,2) NOT NULL,
  max_amount DECIMAL(12,2) NULL,                    -- NULL = no upper limit
  risk_level ENUM('Any','Low','Medium','High') NOT NULL DEFAULT 'Any',
  category   VARCHAR(60) NOT NULL DEFAULT 'Any',
  approver_email VARCHAR(500) NOT NULL,             -- comma-separated when several approvers
  approval_required TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS procurement_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  requester_email VARCHAR(190) NOT NULL,
  vendor_id  INT NULL,
  category   VARCHAR(60) NOT NULL,
  amount     DECIMAL(12,2) NOT NULL,
  status     VARCHAR(40) NOT NULL DEFAULT 'New',
  required_by_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_cases (
  case_id     VARCHAR(20) NOT NULL PRIMARY KEY,
  thread_id   VARCHAR(100) NOT NULL,
  real_sender_email VARCHAR(190) NOT NULL,
  request_type VARCHAR(40) NOT NULL,
  linked_vendor_id  INT NULL,
  linked_request_id INT NULL,
  current_status VARCHAR(40) NOT NULL DEFAULT 'New',
  next_action VARCHAR(500) NOT NULL DEFAULT '',
  owner       VARCHAR(190) NOT NULL DEFAULT '',
  follow_up_due_at DATE NULL,                      -- [PART 3] structured due date used by cron/followup.php
  last_chase_at TIMESTAMP NULL,
  final_deadline_at DATE NULL,
  chase_count INT NOT NULL DEFAULT 0,              -- [PART 3] chase emails sent for the current Pending Documents decision
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_case_thread (thread_id),
  KEY ix_case_followup (current_status, follow_up_due_at),
  KEY ix_case_created (created_at),
  CONSTRAINT fk_wc_vendor  FOREIGN KEY (linked_vendor_id)  REFERENCES vendors(vendor_id) ON DELETE RESTRICT,
  CONSTRAINT fk_wc_request FOREIGN KEY (linked_request_id) REFERENCES procurement_requests(request_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  log_id  BIGINT AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(20) NOT NULL,
  action  VARCHAR(40) NOT NULL,
  decision_reason TEXT NOT NULL,
  rule_id VARCHAR(40) NOT NULL,                    -- text, no FK: DOC-001, AM-2, FU-001 ...
  actor   VARCHAR(190) NOT NULL,
  evidence LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_case (case_id, log_id),
  CONSTRAINT fk_audit_case FOREIGN KEY (case_id) REFERENCES workflow_cases(case_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_log (                -- SMTP is simulated: every outbound mail is recorded here
  mail_id INT AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(20) NULL,
  recipient_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body    TEXT NOT NULL,
  mail_type VARCHAR(40) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_mail_case (case_id, mail_id),
  CONSTRAINT fk_mail_case FOREIGN KEY (case_id) REFERENCES workflow_cases(case_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- [PART 3] configuration changes (approval_matrix edits). Kept apart from audit_log, which requires a case_id.
CREATE TABLE IF NOT EXISTS config_audit_log (
  config_audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(60) NOT NULL,
  record_key VARCHAR(60) NOT NULL,
  changed_by VARCHAR(190) NOT NULL,
  old_value  LONGTEXT NOT NULL,
  new_value  LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cfg_table (table_name, record_key)
) ENGINE=InnoDB;

-- ===================== seed data =====================
INSERT IGNORE INTO settings (key_name, `value`, description) VALUES
 ('follow_up_days','3','Days between document chase emails'),
 ('manual_review_owner','manual.review@novalite.example','Owner of Manual Review cases'),
 ('system_from_email','procurement.bot@novalite.example','Sender/owner for automated actions'),
 ('case_prefix','VP','Case id prefix'),
 ('case_next_number','1001','Next case number (locked + incremented in a transaction)'),
 ('business_date','2026-10-10','Business date used for expiry/follow-up (remove to use DB CURDATE())'),
 ('classification_confidence_threshold','0.70','Minimum classifier confidence'),
 ('hard_stop_vendor_statuses','Blocked','Vendor statuses that always go to Manual Review'),
 ('open_request_statuses','New','Request statuses eligible for matching'),
 ('max_follow_up_chases','2','[PART 3] Chase emails sent before escalating to Manual Review');

INSERT IGNORE INTO document_rules (document_type, is_mandatory, active, scope, sort_order) VALUES
 ('GST Certificate',1,1,'ALL_VENDORS',1),('Tax Certificate',1,1,'ALL_VENDORS',2),
 ('Insurance',1,1,'ALL_VENDORS',3),('Bank Verification',1,1,'ALL_VENDORS',4);

INSERT IGNORE INTO contact_map (contact_id, persona_name, role_title, contact_type, pack_email, delivery_mailbox) VALUES
 (1,'Priya Nair','Procurement Manager','staff','procurement.manager@novalite.example','pack-inbox-1@novalite.test'),
 (2,'Rahul Mehta','Finance Head','staff','finance.head@novalite.example','pack-inbox-2@novalite.test'),
 (3,'Sunita Rao','Procurement Head','staff','procurement.head@novalite.example','pack-inbox-3@novalite.test'),
 (4,'Anil Kapoor','Compliance Owner','staff','compliance.owner@novalite.example','pack-inbox-4@novalite.test'),
 (5,'Meera Amin','Requester','staff','meera@novalite.example','pack-inbox-5@novalite.test'),
 (6,'Apex Industrial Supplies','Vendor','vendor','accounts@apex-industrial.example','pack-inbox-6@novalite.test'),
 (7,'BlueRiver Logistics','Vendor','vendor','accounts@blueriver-logistics.example','pack-inbox-7@novalite.test'),
 (8,'Dunmore Traders','Vendor','vendor','accounts@dunmore-traders.example','pack-inbox-8@novalite.test');

INSERT IGNORE INTO vendors (vendor_id, vendor_name, vendor_email, gst_id, vendor_status, risk_level, bank_verification_status) VALUES
 (1,'Apex Industrial Supplies','accounts@apex-industrial.example','24AAAAA0000A1Z5','Active','Low','Verified'),
 (2,'BlueRiver Logistics','accounts@blueriver-logistics.example',NULL,'Pending Documents','Medium','Verified'),
 (3,'Dunmore Traders','accounts@dunmore-traders.example','24BBBBB1111B1Z6','Blocked','High','Failed');

INSERT IGNORE INTO vendor_documents (vendor_id, document_type, verification_status, expiry_date) VALUES
 (1,'GST Certificate','Verified','2027-12-31'),(1,'Tax Certificate','Verified','2027-12-31'),
 (1,'Insurance','Verified','2027-12-31'),(1,'Bank Verification','Verified','2027-12-31'),
 (2,'GST Certificate','Missing',NULL),(2,'Tax Certificate','Verified','2027-12-31'),
 (2,'Insurance','Verified','2027-12-31'),(2,'Bank Verification','Verified','2027-12-31'),
 (3,'GST Certificate','Verified','2027-12-31'),(3,'Tax Certificate','Verified','2027-12-31'),
 (3,'Insurance','Expired','2026-01-31'),(3,'Bank Verification','Pending',NULL);

INSERT IGNORE INTO approval_matrix (rule_id, min_amount, max_amount, risk_level, category, approver_email, approval_required, description) VALUES
 (1,0.00,50000.00,'Any','Any','auto-process@novalite.example',0,'Auto-process'),
 (2,50000.01,200000.00,'Any','Any','procurement.manager@novalite.example',1,'Procurement manager'),
 (3,200000.01,NULL,'Any','Any','finance.head@novalite.example,procurement.head@novalite.example',1,'Finance head and procurement head'),
 (4,0.00,NULL,'High','Any','compliance.owner@novalite.example',1,'High-risk catch-all');

INSERT IGNORE INTO procurement_requests (request_id, requester_email, vendor_id, category, amount, status, required_by_date) VALUES
 (1,'meera@novalite.example',1,'Office Supplies',32500.00,'New','2026-11-05'),
 (2,'meera@novalite.example',1,'IT Hardware',125000.00,'New','2026-11-10'),
 (3,'meera@novalite.example',1,'Capital Equipment',650000.00,'New','2026-12-01'),
 (4,'meera@novalite.example',2,'Logistics Services',45000.00,'New','2026-11-15'),
 (5,'meera@novalite.example',3,'Raw Materials',78000.00,'New','2026-11-20');

-- ===================== [PART 3] migration for an EXISTING Part 1 database =====================
-- Fresh installs already have these columns/settings from the CREATE TABLE statements above.
-- On an existing Part 1 database run (MariaDB/XAMPP; on MySQL remove "IF NOT EXISTS" and run once):
--   ALTER TABLE workflow_cases ADD COLUMN IF NOT EXISTS follow_up_due_at DATE NULL AFTER owner;
--   ALTER TABLE workflow_cases ADD COLUMN IF NOT EXISTS chase_count INT NOT NULL DEFAULT 0 AFTER follow_up_due_at;
--   ALTER TABLE workflow_cases ADD KEY ix_case_followup (current_status, follow_up_due_at);
--   (then re-run the CREATE TABLE config_audit_log and the max_follow_up_chases INSERT IGNORE above)
