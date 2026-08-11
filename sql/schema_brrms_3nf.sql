-- ============================================================================
--  BARANGAY RECORDS & REPORTING MANAGEMENT SYSTEM (BRRMS)
--  Normalized (3NF) Relational Schema — Production-Ready DDL
-- ----------------------------------------------------------------------------
--  Scope : Users & Roles, Residents & Households, Document Requests & OR
--          Payments, Blotter Complaints & Hearing Proceedings, Audit Trails.
--  Engine: InnoDB (transactional, FK-enforcing, row-level locking)
--  Charset: utf8mb4 (full Unicode incl. emoji in free-text narrative)
--  Design : 3NF — every non-key attribute depends on the key, the whole key,
--           and nothing but the key. Lookup lists are factored into reference
--           tables; repeated groups are split into child tables; no derived
--           columns are stored (e.g. household head count is computed, not saved).
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";
SET FOREIGN_KEY_CHECKS = 0;   -- disabled during load; re-enabled at end

-- ============================================================================
--  SECTION 0 — ENUM-LIKE REFERENCE LOOKUPS (promote magic strings to 3NF tables)
-- ============================================================================

-- 0.1 Purok / Zone master (geographic subdivision of the barangay)
CREATE TABLE `puroks` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `purok_name`    VARCHAR(100) NOT NULL,
  `zone_number`   INT(11)      DEFAULT NULL,
  `description`   VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purok_name` (`purok_name`)          -- purok names are unique
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Geographic subdivision master (purok/zone)';

-- ============================================================================
--  SECTION 1 — USERS & ROLES
--  Roles: Captain, Secretary, Treasurer, Staff (+ resident for self-service).
--  Normalized so role is a FK to `roles`, not a repeating enum, allowing the
--  Captain/Secretary/Treasurer positions to be assigned/audited cleanly (3NF).
-- ============================================================================

-- 1.1 Role catalog (the four mandated elective roles + system roles)
CREATE TABLE `roles` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `role_code`   VARCHAR(30)  NOT NULL,                 -- captain|secretary|treasurer|staff|resident
  `role_name`   VARCHAR(80)  NOT NULL,                 -- human label
  `description` VARCHAR(255) DEFAULT NULL,
  `can_approve_documents` TINYINT(1) NOT NULL DEFAULT 0,
  `can_issue_or`           TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_residents`   TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Role catalog — Captain/Secretary/Treasurer/Staff';

-- 1.2 Application users (accounts). One row per login identity.
CREATE TABLE `users` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `role_id`           INT(11)      DEFAULT NULL,        -- FK -> roles (nullable for pre-provisioning)
  `username`          VARCHAR(100) NOT NULL,
  `email`             VARCHAR(150) NOT NULL,
  `password_hash`     VARCHAR(255) NOT NULL,            -- bcrypt/argon2; NEVER plaintext
  `full_name`         VARCHAR(200) NOT NULL,
  `resident_id`       INT(11)      DEFAULT NULL,        -- FK -> residents (self-service residents)
  `status`            ENUM('active','inactive','pending','locked') NOT NULL DEFAULT 'pending',
  `phone_number`      VARCHAR(20)  DEFAULT NULL,
  `last_login`        DATETIME     DEFAULT NULL,
  `twofa_enabled`     TINYINT(1)   NOT NULL DEFAULT 0,
  `twofa_secret`      VARCHAR(255) DEFAULT NULL,
  `reset_token`       VARCHAR(64)  DEFAULT NULL,
  `reset_token_expiry` DATETIME    DEFAULT NULL,
  `reset_code`        VARCHAR(6)   DEFAULT NULL,
  `reset_code_expiry` DATETIME     DEFAULT NULL,
  `failed_logins`     TINYINT(3)   NOT NULL DEFAULT 0,  -- brute-force guard
  `locked_until`      DATETIME     DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_user_status` (`status`),
  KEY `idx_user_role` (`role_id`),
  KEY `idx_user_resident` (`resident_id`),
  CONSTRAINT `fk_user_role`     FOREIGN KEY (`role_id`)   REFERENCES `roles`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System user accounts';

-- 1.3 Elected/Appointed Officials (org chart). Captain/Secretary/Treasurer land here.
--     Separate from `users` because an official may not have a login, and a user
--     may hold no elective post — avoiding a transitive dependency (3NF).
CREATE TABLE `officials` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      DEFAULT NULL,             -- optional FK -> users (if they have an account)
  `role_id`     INT(11)      NOT NULL,                 -- FK -> roles (position held)
  `first_name`  VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name`   VARCHAR(100) NOT NULL,
  `suffix`      VARCHAR(10)  DEFAULT NULL,
  `contact_number` VARCHAR(20) DEFAULT NULL,
  `email`       VARCHAR(150) DEFAULT NULL,
  `photo_path`  VARCHAR(255) DEFAULT NULL,
  `term_start`  DATE         DEFAULT NULL,
  `term_end`    DATE         DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_official_role` (`role_id`),
  KEY `idx_official_active` (`is_active`),
  KEY `idx_official_user` (`user_id`),
  CONSTRAINT `fk_official_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)     ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_official_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Barangay officials (Captain/Secretary/Treasurer/Staff)';

-- ============================================================================
--  SECTION 2 — RESIDENTS & HOUSEHOLDS
--  3NF: a resident is a person; a household is a place+family. head_id is a
--  self-referential FK (one resident leads the household). Sector flags
--  (senior/pwd/indigent/4ps) are atomic boolean attributes of the resident.
-- ============================================================================

-- 2.1 Residents (individual persons)
CREATE TABLE `residents` (
  `id`                 INT(11)      NOT NULL AUTO_INCREMENT,
  `household_id`       INT(11)      DEFAULT NULL,        -- FK -> households
  `first_name`         VARCHAR(100) NOT NULL,
  `middle_name`        VARCHAR(100) DEFAULT NULL,
  `last_name`          VARCHAR(100) NOT NULL,
  `suffix`             VARCHAR(10)  DEFAULT NULL,
  `birth_date`         DATE         NOT NULL,
  `birth_place`        VARCHAR(200) DEFAULT NULL,
  `gender`             ENUM('Male','Female') NOT NULL,
  `civil_status`       ENUM('Single','Married','Widowed','Separated','Divorced') NOT NULL DEFAULT 'Single',
  `citizenship`        VARCHAR(50)  NOT NULL DEFAULT 'Filipino',
  `religion`           VARCHAR(50)  DEFAULT NULL,
  `occupation`         VARCHAR(100) DEFAULT NULL,
  `contact_number`     VARCHAR(20)  DEFAULT NULL,
  `phone_number`       VARCHAR(20)  DEFAULT NULL,       -- secondary/alt contact
  `email`              VARCHAR(150) DEFAULT NULL,
  `photo_path`         VARCHAR(255) DEFAULT NULL,
  `voter_status`       ENUM('Registered','Not Registered','Pending') NOT NULL DEFAULT 'Not Registered',
  `is_pwd`             TINYINT(1)   NOT NULL DEFAULT 0,
  `is_senior`          TINYINT(1)   NOT NULL DEFAULT 0,  -- can be derived from birth_date but stored for query speed
  `is_indigent`        TINYINT(1)   NOT NULL DEFAULT 0,
  `fourps_beneficiary` TINYINT(1)   NOT NULL DEFAULT 0,
  `purok_id`           INT(11)      DEFAULT NULL,        -- FK -> puroks (residence location)
  `status`             ENUM('Active','Deceased','Moved Out') NOT NULL DEFAULT 'Active',
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_res_household` (`household_id`),
  KEY `idx_res_purok` (`purok_id`),
  KEY `idx_res_status` (`status`),
  KEY `idx_res_name` (`last_name`,`first_name`),        -- common lookup by name
  KEY `idx_res_sector` (`is_senior`,`is_pwd`,`is_indigent`,`fourps_beneficiary`),
  CONSTRAINT `fk_res_household` FOREIGN KEY (`household_id`) REFERENCES `households`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_res_purok`     FOREIGN KEY (`purok_id`)     REFERENCES `puroks`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Individual resident master record';

-- 2.2 Households (family dwelling unit)
CREATE TABLE `households` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `household_number` VARCHAR(50)  NOT NULL,
  `head_id`          INT(11)      DEFAULT NULL,         -- FK -> residents (self-referential)
  `purok_id`         INT(11)      NOT NULL,             -- FK -> puroks
  `address`          VARCHAR(255) NOT NULL,
  `house_type`       VARCHAR(50)  DEFAULT NULL,         -- Concrete|Semi-concrete|Light
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_household_number` (`household_number`),
  KEY `idx_hh_purok` (`purok_id`),
  KEY `idx_hh_head` (`head_id`),
  CONSTRAINT `fk_household_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks`(`id`)   ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_household_head`  FOREIGN KEY (`head_id`)  REFERENCES `residents`(`id`) ON DELETE SET NULL  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Household master record';

-- ============================================================================
--  SECTION 3 — DOCUMENT REQUESTS & OFFICIAL RECEIPT (OR) PAYMENTS
--  Document fee lives in `document_types` (no repetition). Payment is a separate
--  `official_receipts` table (1 request may have 1 OR; OR is the financial fact).
-- ============================================================================

-- 3.1 Document type catalog (fee reference — normalized, not duplicated per request)
CREATE TABLE `document_types` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `document_code` VARCHAR(30) NOT NULL,                -- clearance|indigency|residency|barangay_pass
  `document_name` VARCHAR(150) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `fee`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `requires_or`   TINYINT(1)   NOT NULL DEFAULT 1,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_code` (`document_code`),
  UNIQUE KEY `uk_document_name` (`document_name`),
  KEY `idx_doc_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Document type & standard fee catalog';

-- 3.2 Document requests (the service transaction)
CREATE TABLE `document_requests` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `request_code`     VARCHAR(50)  NOT NULL,            -- human-friendly control no.
  `resident_id`      INT(11)      NOT NULL,            -- FK -> residents
  `document_type_id` INT(11)      NOT NULL,            -- FK -> document_types
  `or_number`        VARCHAR(50)  DEFAULT NULL,        -- FK-style ref -> official_receipts.or_number
  `purpose`          TEXT         DEFAULT NULL,
  `status`           ENUM('Pending','Processing','Ready for Pickup','Released','Cancelled') NOT NULL DEFAULT 'Pending',
  `requested_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`     DATETIME     DEFAULT NULL,
  `released_at`      DATETIME     DEFAULT NULL,
  `processed_by`     INT(11)      DEFAULT NULL,        -- FK -> users
  `remarks`          TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_code` (`request_code`),
  KEY `idx_docreq_resident` (`resident_id`),
  KEY `idx_docreq_type` (`document_type_id`),
  KEY `idx_docreq_status` (`status`),
  KEY `idx_docreq_or` (`or_number`),
  KEY `idx_docreq_requested` (`requested_at`),
  CONSTRAINT `fk_docreq_resident` FOREIGN KEY (`resident_id`)      REFERENCES `residents`(`id`)     ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_docreq_type`     FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_docreq_processor` FOREIGN KEY (`processed_by`)    REFERENCES `users`(`id`)         ON DELETE SET NULL  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Barangay document/certificate requests';

-- 3.3 Official Receipts (OR) — the financial payment record (3NF: payment is its
--     own fact, not embedded in the request).
CREATE TABLE `official_receipts` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `or_number`        VARCHAR(50)  NOT NULL,            -- official receipt series (unique)
  `document_request_id` INT(11)   DEFAULT NULL,        -- FK -> document_requests (nullable for misc fees)
  `resident_id`      INT(11)      NOT NULL,            -- FK -> residents (payer)
  `document_type_id` INT(11)      NOT NULL,            -- FK -> document_types (what was paid for)
  `amount`           DECIMAL(10,2) NOT NULL,
  `payment_method`   ENUM('Cash','GCash','Bank Transfer','Others') NOT NULL DEFAULT 'Cash',
  `received_by`      INT(11)      DEFAULT NULL,        -- FK -> users (treasurer/staff who received)
  `issued_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_or_number` (`or_number`),
  KEY `idx_or_resident` (`resident_id`),
  KEY `idx_or_doctype` (`document_type_id`),
  KEY `idx_or_request` (`document_request_id`),
  KEY `idx_or_issued` (`issued_at`),
  CONSTRAINT `fk_or_resident`   FOREIGN KEY (`resident_id`)        REFERENCES `residents`(`id`)      ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_or_doctype`    FOREIGN KEY (`document_type_id`)   REFERENCES `document_types`(`id`)  ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_or_request`    FOREIGN KEY (`document_request_id`) REFERENCES `document_requests`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_or_receiver`   FOREIGN KEY (`received_by`)        REFERENCES `users`(`id`)          ON DELETE SET NULL  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Official Receipt (OR) payment records';

-- ============================================================================
--  SECTION 4 — BLOTTER COMPLAINTS & HEARING PROCEEDINGS
--  Blotter is the case; hearings are a repeating child table (1 case : many
--  hearings) — this is the core 3NF split that prevents repeating hearing data
--  inside the case row.
-- ============================================================================

-- 4.1 Blotter cases (the complaint/master record)
CREATE TABLE `blotter_cases` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `case_number`         VARCHAR(50)  NOT NULL,
  `case_type`           ENUM('Dispute','Complaint','Incident','Disturbance','Theft','Others') NOT NULL DEFAULT 'Dispute',
  `status`              ENUM('Open','Under Mediation','Conciliated','Arbitrated','Escalated','Closed') NOT NULL DEFAULT 'Open',
  `filing_date`         DATE         NOT NULL,
  `incident_date`       DATE         NOT NULL,
  `incident_time`       TIME         DEFAULT NULL,
  `incident_location`   VARCHAR(255) NOT NULL,
  `narrative`           TEXT         NOT NULL,         -- complainant's statement
  `complainant_id`      INT(11)      DEFAULT NULL,     -- FK -> residents (or NULL for walk-in)
  `respondent_id`       INT(11)      DEFAULT NULL,     -- FK -> residents (if a resident)
  `respondent_name`     VARCHAR(200) DEFAULT NULL,     -- free-text if non-resident
  `assigned_official_id` INT(11)     DEFAULT NULL,     -- FK -> officials (mediator)
  `created_by`          INT(11)      DEFAULT NULL,     -- FK -> users
  `resolution`          TEXT         DEFAULT NULL,
  `resolution_type`     ENUM('Mediated','Conciliated','Arbitrated','Filed in Court','Withdrawn','Dismissed') DEFAULT NULL,
  `closed_at`           DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_number` (`case_number`),
  KEY `idx_blotter_status` (`status`),
  KEY `idx_blotter_type` (`case_type`),
  KEY `idx_blotter_complainant` (`complainant_id`),
  KEY `idx_blotter_respondent` (`respondent_id`),
  KEY `idx_blotter_official` (`assigned_official_id`),
  KEY `idx_blotter_filing` (`filing_date`),
  CONSTRAINT `fk_blotter_complainant` FOREIGN KEY (`complainant_id`)      REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_respondent`  FOREIGN KEY (`respondent_id`)       REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_official`    FOREIGN KEY (`assigned_official_id`) REFERENCES `officials`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_creator`     FOREIGN KEY (`created_by`)          REFERENCES `users`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Blotter/complaint case master';

-- 4.2 Hearing proceedings (child of blotter_cases — one case, many hearings)
CREATE TABLE `hearing_proceedings` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `case_id`          INT(11)      NOT NULL,            -- FK -> blotter_cases
  `hearing_no`       TINYINT(3)   NOT NULL DEFAULT 1,  -- 1st, 2nd, 3rd hearing
  `scheduled_date`   DATETIME     NOT NULL,
  `actual_date`      DATETIME     DEFAULT NULL,
  `venue`            VARCHAR(255) DEFAULT NULL,
  `presided_by`      INT(11)      DEFAULT NULL,        -- FK -> officials (mediator/judge)
  `status`           ENUM('Scheduled','Conducted','Postponed','Cancelled','Adjourned') NOT NULL DEFAULT 'Scheduled',
  `attendees`        TEXT         DEFAULT NULL,        -- complainant & respondent attendance notes
  `minutes`          TEXT         DEFAULT NULL,        -- proceeding minutes / agreement
  `agreement_text`   TEXT         DEFAULT NULL,        -- settlement terms
  `next_action`      VARCHAR(255) DEFAULT NULL,        -- e.g. "Schedule 2nd hearing"
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_hearing` (`case_id`,`hearing_no`),  -- one row per hearing number per case
  KEY `idx_hearing_case` (`case_id`),
  KEY `idx_hearing_presider` (`presided_by`),
  KEY `idx_hearing_sched` (`scheduled_date`),
  KEY `idx_hearing_status` (`status`),
  CONSTRAINT `fk_hearing_case`     FOREIGN KEY (`case_id`)    REFERENCES `blotter_cases`(`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_hearing_presider` FOREIGN KEY (`presided_by`) REFERENCES `officials`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Hearing/mediation proceedings for a blotter case';

-- 4.3 Blotter parties (resolves the "involved_parties" text blob into a proper
--     associative entity — 3NF: a case has many parties with roles).
CREATE TABLE `blotter_parties` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `case_id`    INT(11)      NOT NULL,                 -- FK -> blotter_cases
  `resident_id` INT(11)     DEFAULT NULL,             -- FK -> residents (if resident)
  `party_name` VARCHAR(200) DEFAULT NULL,             -- free-text if non-resident
  `party_role` ENUM('Complainant','Respondent','Witness') NOT NULL,
  `contact_number` VARCHAR(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_party_case` (`case_id`),
  KEY `idx_party_resident` (`resident_id`),
  CONSTRAINT `fk_party_case`     FOREIGN KEY (`case_id`)    REFERENCES `blotter_cases`(`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_party_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Parties involved in a blotter case';

-- ============================================================================
--  SECTION 5 — AUDIT TRAILS & ACTIVITY LOGS
--  Immutable, append-only. JSON columns hold before/after diffs. Indexed for
--  forensic queries by user, module, action, and time range.
-- ============================================================================

CREATE TABLE `audit_logs` (
  `log_id`        BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `timestamp`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`       INT(11)      DEFAULT NULL,           -- FK -> users (NULL = system/anonymous)
  `user_role`     VARCHAR(30)  DEFAULT NULL,           -- denormalized snapshot of role_code at action time
  `action_type`   ENUM('CREATE','READ','UPDATE','DELETE','EXPORT','AUTH','LOGIN','LOGOUT') NOT NULL DEFAULT 'READ',
  `module_name`   VARCHAR(100) NOT NULL,               -- Residents|Documents|Blotter|Accounts|...
  `record_id`     VARCHAR(100) DEFAULT NULL,           -- PK of affected row (string to span tables)
  `old_values`    JSON         DEFAULT NULL,           -- previous state (masked PII)
  `new_values`    JSON         DEFAULT NULL,           -- new state (masked PII)
  `ip_address`    VARCHAR(45)  DEFAULT NULL,           -- IPv4/IPv6
  `user_agent`    TEXT         DEFAULT NULL,
  `severity_level` ENUM('INFO','WARN','CRITICAL') NOT NULL DEFAULT 'INFO',
  `session_id`    VARCHAR(128) DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_audit_timestamp` (`timestamp`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_module_action` (`module_name`,`action_type`),
  KEY `idx_audit_action` (`action_type`),
  KEY `idx_audit_severity` (`severity_level`),
  KEY `idx_audit_user_time` (`user_id`,`timestamp`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Immutable audit trail / activity log';

-- ============================================================================
--  INDEXING STRATEGY (rationale)
--  ---------------------------------------------------------------------------
--  * PRIMARY KEY on every table (surrogate AUTO_INCREMENT except composite
--    uniques for associative/child rows).
--  * UNIQUE keys enforce business identities: username, email, or_number,
--    case_number, request_code, household_number, role_code, doc_code.
--  * FOREIGN-KEY indexes (InnoDB requires them) back every JOIN path.
--  * Composite indexes target the most common report filters:
--      - residents (last_name,first_name) for name search
--      - residents (is_senior,is_pwd,is_indigent,fourps) for sector reports
--      - document_requests (status, requested_at) for queue dashboards
--      - blotter (status, filing_date) for aging reports
--      - audit (module_name, action_type) and (user_id, timestamp) for forensics
--  * ON DELETE policy: RESTRICT on lookup parents (roles, document_types,
--    puroks) to protect referential integrity; SET NULL on optional links
--    (head_id, assigned_official, created_by); CASCADE only on true ownership
--    (hearing_proceedings, blotter_parties depend on their case).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
