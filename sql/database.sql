SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `role` enum('admin','staff','official','resident') NOT NULL DEFAULT 'resident',
  `resident_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `twofa_secret` varchar(255) DEFAULT NULL,
  `twofa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `reset_code` varchar(6) DEFAULT NULL,
  `reset_code_expiry` datetime DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_resident_id` (`resident_id`),
  KEY `idx_reset_token` (`reset_token`),
  KEY `idx_reset_code` (`reset_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CORE: RESIDENTS PROFILING
-- ============================================================

CREATE TABLE `residents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `birth_date` date NOT NULL,
  `birth_place` varchar(200) DEFAULT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Divorced') NOT NULL DEFAULT 'Single',
  `citizenship` varchar(50) NOT NULL DEFAULT 'Filipino',
  `religion` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `voter_status` enum('Registered','Not Registered','Pending') NOT NULL DEFAULT 'Not Registered',
  `is_pwd` tinyint(1) NOT NULL DEFAULT 0,
  `is_senior` tinyint(1) NOT NULL DEFAULT 0,
  `is_indigent` tinyint(1) NOT NULL DEFAULT 0,
  `fourps_beneficiary` tinyint(1) NOT NULL DEFAULT 0,
  `household_id` int(11) DEFAULT NULL,
  `purok_id` int(11) DEFAULT NULL,
  `status` enum('Active','Deceased','Moved Out') NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`last_name`,`first_name`),
  KEY `idx_household` (`household_id`),
  KEY `idx_purok` (`purok_id`),
  KEY `idx_status` (`status`),
  KEY `idx_birth_date` (`birth_date`),
  FULLTEXT KEY `idx_full_name` (`first_name`,`middle_name`,`last_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CORE: HOUSEHOLDS & PUROKS
-- ============================================================

CREATE TABLE `puroks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purok_name` varchar(100) NOT NULL,
  `zone_number` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purok_name` (`purok_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `households` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `household_number` varchar(50) NOT NULL,
  `head_id` int(11) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `purok_id` int(11) NOT NULL,
  `number_of_members` int(11) NOT NULL DEFAULT 1,
  `house_type` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_household_number` (`household_number`),
  KEY `idx_purok` (`purok_id`),
  KEY `idx_head` (`head_id`),
  CONSTRAINT `fk_household_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_household_head` FOREIGN KEY (`head_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `residents`
  ADD CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`) REFERENCES `households`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_resident_purok` FOREIGN KEY (`purok_id`) REFERENCES `puroks`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ============================================================
-- CORE: BARANGAY OFFICIALS
-- ============================================================

CREATE TABLE `officials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `position` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `term_start` date DEFAULT NULL,
  `term_end` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_position` (`position`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DOCUMENTS: DOCUMENT TYPES & FEES
-- ============================================================

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `requires_or` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_name` (`document_name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DOCUMENTS: DOCUMENT REQUESTS
-- ============================================================

CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resident_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `or_number` varchar(50) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('Pending','Processing','Ready for Pickup','Released','Cancelled') NOT NULL DEFAULT 'Pending',
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_resident` (`resident_id`),
  KEY `idx_doc_type` (`document_type_id`),
  KEY `idx_status` (`status`),
  KEY `idx_or` (`or_number`),
  KEY `idx_requested` (`requested_at`),
  CONSTRAINT `fk_doc_req_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_doc_req_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_doc_req_processed` FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DOCUMENTS: OFFICIAL RECEIPTS (OR)
-- ============================================================

CREATE TABLE `official_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `or_number` varchar(50) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','GCash','Bank Transfer','Others') NOT NULL DEFAULT 'Cash',
  `received_by` int(11) DEFAULT NULL,
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_or_number` (`or_number`),
  KEY `idx_resident` (`resident_id`),
  KEY `idx_doc_type` (`document_type_id`),
  KEY `idx_issued` (`issued_at`),
  CONSTRAINT `fk_or_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_or_doc_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_or_received` FOREIGN KEY (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOTTER: CASES & MEDIATION
-- ============================================================

CREATE TABLE `blotter_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_number` varchar(50) NOT NULL,
  `case_type` enum('Dispute','Complaint','Incident','Disturbance','Theft','Others') NOT NULL DEFAULT 'Dispute',
  `status` enum('Open','Under Mediation','Conciliated','Arbitrated','Escalated','Closed') NOT NULL DEFAULT 'Open',
  `filing_date` date NOT NULL,
  `incident_date` date NOT NULL,
  `incident_time` time DEFAULT NULL,
  `incident_location` varchar(255) NOT NULL,
  `involved_parties` text NOT NULL,
  `narrative` text NOT NULL,
  `complainant_id` int(11) DEFAULT NULL,
  `respondent_id` int(11) DEFAULT NULL,
  `assigned_official_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `resolution` text DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_number` (`case_number`),
  KEY `idx_status` (`status`),
  KEY `idx_complainant` (`complainant_id`),
  KEY `idx_respondent` (`respondent_id`),
  KEY `idx_official` (`assigned_official_id`),
  KEY `idx_filing_date` (`filing_date`),
  CONSTRAINT `fk_blotter_complainant` FOREIGN KEY (`complainant_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_respondent` FOREIGN KEY (`respondent_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_official` FOREIGN KEY (`assigned_official_id`) REFERENCES `officials`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_blotter_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- HEALTH: COMMUNITY HEALTH
-- ============================================================

CREATE TABLE `health_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `resident_id` int(11) NOT NULL,
  `blood_type` enum('A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown') NOT NULL DEFAULT 'Unknown',
  `height_cm` int(11) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(5,2) DEFAULT NULL,
  `vaccination_status` enum('Fully Vaccinated','Partially Vaccinated','Not Vaccinated','Unknown') NOT NULL DEFAULT 'Unknown',
  `medical_conditions` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `last_checkup` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resident` (`resident_id`),
  CONSTRAINT `fk_health_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- WELFARE: PROGRAMS & BENEFICIARIES
-- ============================================================

CREATE TABLE `welfare_programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `beneficiary_type` varchar(100) DEFAULT NULL,
  `status` enum('Upcoming','Ongoing','Completed','Cancelled') NOT NULL DEFAULT 'Upcoming',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `welfare_beneficiaries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_id` int(11) NOT NULL,
  `resident_id` int(11) NOT NULL,
  `enrollment_date` date NOT NULL,
  `status` enum('Enrolled','Completed','Dropped') NOT NULL DEFAULT 'Enrolled',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_program` (`program_id`),
  KEY `idx_resident` (`resident_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_welfare_program` FOREIGN KEY (`program_id`) REFERENCES `welfare_programs`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_welfare_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SECURITY: AUDIT LOGS
-- ============================================================

CREATE TABLE `audit_logs` (
  `log_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int(11) DEFAULT NULL,
  `user_role` enum('admin','staff','official','resident') DEFAULT NULL,
  `action_type` enum('CREATE','READ','UPDATE','DELETE','EXPORT','AUTH') NOT NULL DEFAULT 'READ',
  `module_name` varchar(100) NOT NULL,
  `record_id` varchar(100) DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `severity_level` enum('INFO','WARN','CRITICAL') NOT NULL DEFAULT 'INFO',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_module_action` (`module_name`,`action_type`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_severity` (`severity_level`),
  KEY `idx_user_time` (`user_id`,`timestamp`),
  KEY `idx_module_time` (`module_name`,`timestamp`),
  CONSTRAINT `fk_audit_user_new` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT LOGS: APPEND-ONLY ENFORCEMENT TRIGGERS
-- Prevents UPDATE and DELETE operations on audit_logs table.
-- ============================================================
-- Trigger: Prevent UPDATE on audit_logs
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_no_update`
BEFORE UPDATE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit logs are append-only. UPDATE operations are not permitted.';
END$$
DELIMITER ;

-- Trigger: Prevent DELETE on audit_logs
DELIMITER $$
CREATE TRIGGER `trg_audit_logs_no_delete`
BEFORE DELETE ON `audit_logs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit logs are append-only. DELETE operations are not permitted.';
END$$
DELIMITER ;

-- ============================================================
-- SYSTEM: SETTINGS
-- ============================================================

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BROADCAST & COMMUNICATION MODULE
-- ============================================================

CREATE TABLE `broadcasts` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`       ENUM('EMERGENCY','ASSEMBLY','HEALTH','CUSTOM') NOT NULL,
  `title`          VARCHAR(255) NOT NULL,
  `message`        TEXT NOT NULL,
  `sender_id`      INT(11) NOT NULL,
  `sender_role`    ENUM('admin','staff') NOT NULL,
  `audience_filter` JSON DEFAULT NULL,
  `recipient_count` INT(11) DEFAULT 0,
  `cost`           DECIMAL(10,2) DEFAULT 0.00,
  `status`         ENUM('DRAFT','SCHEDULED','QUEUED','SENDING','COMPLETED','FAILED','CANCELLED') DEFAULT 'DRAFT',
  `priority`       TINYINT(1) DEFAULT 1,
  `scheduled_at`   DATETIME NULL,
  `sent_at`        DATETIME NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_category` (`category`),
  KEY `idx_sender` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `broadcast_deliveries` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id`     BIGINT(20) UNSIGNED NOT NULL,
  `recipient_id`     INT(11) DEFAULT NULL,
  `phone_number`     VARCHAR(20) NOT NULL,
  `status`           ENUM('PENDING','SENT','DELIVERED','FAILED','CANCELLED') DEFAULT 'PENDING',
  `gateway_response` TEXT DEFAULT NULL,
  `gateway_message_id` VARCHAR(100) DEFAULT NULL,
  `attempts`         TINYINT(1) DEFAULT 0,
  `sent_at`          DATETIME NULL,
  `delivered_at`     DATETIME NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_status` (`broadcast_id`,`status`),
  KEY `idx_phone` (`phone_number`),
  KEY `idx_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_broadcast_delivery` FOREIGN KEY (`broadcast_id`)
    REFERENCES `broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `broadcast_templates` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL,
  `category`       ENUM('EMERGENCY','ASSEMBLY','HEALTH','CUSTOM') NOT NULL,
  `subject`        VARCHAR(255) NOT NULL,
  `message_template` TEXT NOT NULL,
  `merge_tags`     JSON DEFAULT NULL,
  `is_active`      TINYINT(1) DEFAULT 1,
  `created_by`     INT(11) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scheduled_broadcasts` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` BIGINT(20) UNSIGNED NOT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `status`       ENUM('PENDING','PROCESSING','COMPLETED','FAILED','CANCELLED') DEFAULT 'PENDING',
  `cron_job_id`  VARCHAR(100) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scheduled` (`scheduled_at`,`status`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_scheduled_broadcast` FOREIGN KEY (`broadcast_id`)
    REFERENCES `broadcasts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_queue` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id` BIGINT(20) UNSIGNED NOT NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `message`      TEXT NOT NULL,
  `gateway`      ENUM('semaphore','itexmo','twilio') DEFAULT 'semaphore',
  `priority`     TINYINT(1) DEFAULT 1,
  `attempts`     TINYINT(1) DEFAULT 0,
  `max_attempts` TINYINT(1) DEFAULT 5,
  `status`       ENUM('PENDING','PROCESSING','SENT','DELIVERED','FAILED','CANCELLED') DEFAULT 'PENDING',
  `scheduled_at` DATETIME NULL,
  `sent_at`      DATETIME NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`,`priority`,`created_at`),
  KEY `idx_broadcast` (`broadcast_id`),
  KEY `idx_scheduled` (`scheduled_at`),
  KEY `idx_phone_created` (`phone_number`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gateway_credentials` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `provider`   ENUM('semaphore','itexmo','twilio') NOT NULL,
  `api_key`    TEXT NOT NULL,
  `api_secret` TEXT DEFAULT NULL,
  `sender_id`  VARCHAR(50) DEFAULT NULL,
  `is_active`  TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documents` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `resident_id`   INT(11) NOT NULL,
  `document_type` ENUM('clearance','indigency','residency','barangay_pass') NOT NULL,
  `control_number` VARCHAR(50) NOT NULL,
  `or_number`     VARCHAR(50) DEFAULT NULL,
  `or_series`     VARCHAR(20) DEFAULT NULL,
  `ctc_number`    VARCHAR(50) DEFAULT NULL,
  `ctc_date`      DATE DEFAULT NULL,
  `dry_seal`      TINYINT(1) DEFAULT 0,
  `purpose`       TEXT DEFAULT NULL,
  `amount`        DECIMAL(10,2) DEFAULT NULL,
  `status`        ENUM('DRAFT','PENDING_REVIEW','APPROVED','REJECTED','QUEUED_FOR_PRINT','PRINTED_AND_ISSUED') DEFAULT 'DRAFT',
  `created_by`    INT(11) DEFAULT NULL,
  `approved_by`   INT(11) DEFAULT NULL,
  `approved_at`   DATETIME NULL,
  `printed_at`    DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_control` (`control_number`),
  KEY `idx_resident` (`resident_id`),
  KEY `idx_status` (`status`),
  UNIQUE KEY `uk_control_number` (`control_number`),
  CONSTRAINT `fk_document_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_approvals` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `document_id`  INT(11) NOT NULL,
  `approver_id`  INT(11) NOT NULL,
  `approver_role` VARCHAR(50) NOT NULL,
  `action`       ENUM('review','approve','reject') NOT NULL,
  `notes`        TEXT DEFAULT NULL,
  `document_hash` VARCHAR(64) DEFAULT NULL,
  `signature_data` TEXT DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_approver` (`approver_id`),
  CONSTRAINT `fk_approval_document` FOREIGN KEY (`document_id`)
    REFERENCES `documents`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_approver` FOREIGN KEY (`approver_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `print_queue` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `document_id`   INT(11) NOT NULL,
  `priority`      TINYINT(1) DEFAULT 1,
  `status`        ENUM('PENDING','PRINTING','COMPLETED','FAILED','REISSUE') DEFAULT 'PENDING',
  `attempts`      TINYINT(1) DEFAULT 0,
  `max_attempts`  TINYINT(1) DEFAULT 3,
  `error_message` TEXT DEFAULT NULL,
  `queued_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at`    DATETIME NULL,
  `completed_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_priority_status` (`priority`,`status`,`queued_at`),
  KEY `idx_document` (`document_id`),
  CONSTRAINT `fk_print_document` FOREIGN KEY (`document_id`)
    REFERENCES `documents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `digital_signatures` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11) NOT NULL,
  `signature_data` TEXT NOT NULL COMMENT 'Base64 encoded signature image or overlay coords',
  `document_hash` VARCHAR(64) NOT NULL,
  `secret_key`   VARCHAR(100) DEFAULT NULL,
  `is_active`    TINYINT(1) DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  UNIQUE KEY `uk_user_active` (`user_id`,`is_active`),
  CONSTRAINT `fk_signature_user` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exported_files` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `exporter_id`   INT(11) NOT NULL,
  `exporter_role` VARCHAR(50) NOT NULL,
  `report_type`   VARCHAR(100) NOT NULL,
  `filter_criteria` JSON DEFAULT NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `record_count`  INT(11) DEFAULT 0,
  `file_size`     INT(11) DEFAULT NULL,
  `pii_fields_masked` JSON DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exporter` (`exporter_id`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA: BARANGAY BIDDUANG SYSTEM SETTINGS
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('barangay_name', 'Barangay Bidduang', 'Official barangay name'),
('municipality', 'Municipality of Talavera', 'Municipality'),
('province', 'Nueva Ecija', 'Province'),
('logo_path', 'assets/img/Brgy_Logo.png', 'Path to official logo'),
('portal_title', 'Barangay Bidduang Portal', 'Portal browser tab title'),
('admin_email', 'admin@bidduang.gov.ph', 'Default administrator email'),
('or_prefix', 'OR-2026-', 'Official Receipt number prefix'),
('case_prefix', 'BLT-2026-', 'Blotter case number prefix'),
('doc_request_prefix', 'DR-2026-', 'Document request number prefix'),
('maintenance_mode', '0', '0 = off, 1 = on'),
('site_contact', 'Barangay Bidduang Hall', 'Contact information display');

-- ============================================================
-- SEED DATA: SAMPLE DOCUMENT TYPES
-- ============================================================

INSERT INTO `document_types` (`document_name`, `description`, `fee`, `requires_or`) VALUES
('Barangay Clearance', 'General identification for employment, banking, and government applications.', 50.00, 1),
('Certificate of Indigency', 'For medical, financial, educational, or legal assistance (DSWD, PCSO, scholarships).', 0.00, 0),
('Certificate of Residency', 'Proof of address for school enrollment, utility connections, and loans.', 30.00, 1),
('Barangay Business Permit / Clearance', 'For operating local businesses and sari-sari stores.', 100.00, 1),
('Certificate of Good Moral Character', 'For academic and employment background checks.', 40.00, 1),
('First-Time Jobseeker Certificate (RA 11261)', 'Fee waiver certificate for first-time job applicants.', 0.00, 0),
('Barangay Identification Card', 'Official resident identification card.', 20.00, 1);

-- ============================================================
-- SEED DATA: DEFAULT ADMIN ACCOUNT
-- Username: admin | Password: Admin@123 (change on first login)
-- Hash generated with password_hash('Admin@123', PASSWORD_DEFAULT)
-- ============================================================

INSERT INTO `users` (`username`, `email`, `password`, `full_name`, `role`, `status`, `phone_number`) VALUES
('admin', 'admin@bidduang.gov.ph', '$2y$10$OGnaKFn8sF/SAc5yNjqpeOlfYFbmloXfgCAppTU9okVFSNEtaT8qW', 'System Administrator', 'admin', 'active', '+639XXXXXXXXX');

-- ============================================================
-- SEED DATA: SAMPLE PUROKS
-- ============================================================

INSERT INTO `puroks` (`purok_name`, `zone_number`, `description`) VALUES
('Purok 1', 1, ''),
('Purok 2', 2, ''),
('Purok 3', 3, ''),
('Purok 4', 4, ''),
('Purok 5', 5, ''),
('Purok 6', 6, '');

COMMIT;
