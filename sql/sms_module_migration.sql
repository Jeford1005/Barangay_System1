-- ============================================================================
--  Cloud SMS Integration Module — Database Migration
--  BRRMS (Barangay Records & Reporting Management System)
--  Run after the core schema; idempotent-friendly (IF NOT EXISTS / ADD COLUMN IGNORE).
-- ============================================================================

-- ----------------------------------------------------------------------------
--  SmsLogs : audit trail for every outbound SMS attempt
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `SmsLogs` (
  `LogID`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `RecipientNumber`      VARCHAR(20)  NOT NULL,
  `MessageBody`          TEXT         NOT NULL,
  `Category`             ENUM('Document','Summons','Broadcast','Custom') NOT NULL DEFAULT 'Custom',
  `GatewayResponseCode`  VARCHAR(32)  DEFAULT NULL,
  `DeliveryStatus`       ENUM('Pending','Sent','Delivered','Failed') NOT NULL DEFAULT 'Pending',
  `ReferenceID`          INT(11)      DEFAULT NULL,
  `Gateway`              VARCHAR(32)  DEFAULT NULL,
  `SegmentIndex`         TINYINT(3)   DEFAULT 0,
  `SegmentCount`         TINYINT(3)   DEFAULT 1,
  `Timestamp`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`LogID`),
  KEY `idx_category_status` (`Category`, `DeliveryStatus`),
  KEY `idx_recipient` (`RecipientNumber`),
  KEY `idx_reference` (`ReferenceID`),
  KEY `idx_timestamp` (`Timestamp`),
  KEY `idx_status_ts` (`DeliveryStatus`, `Timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
--  sms_outbox : asynchronous dispatch queue (non-blocking worker)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_outbox` (
  `id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient`     VARCHAR(20)  NOT NULL,
  `category`      ENUM('Document','Summons','Broadcast','Custom') NOT NULL DEFAULT 'Custom',
  `message_body`  TEXT         NOT NULL,
  `reference_id`  INT(11)      DEFAULT NULL,
  `recipient_name` VARCHAR(100) DEFAULT NULL,
  `priority`      TINYINT(1)   DEFAULT 1,
  `status`        ENUM('QUEUED','PROCESSING','RETRY','SENT','FAILED','CANCELLED') NOT NULL DEFAULT 'QUEUED',
  `attempts`      TINYINT(2)   DEFAULT 0,
  `max_attempts`  TINYINT(2)   DEFAULT 3,
  `error_message` TEXT         DEFAULT NULL,
  `scheduled_at`  DATETIME     NULL,
  `sent_at`       DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_priority` (`status`, `priority`, `created_at`),
  KEY `idx_scheduled` (`scheduled_at`),
  KEY `idx_recipient_created` (`recipient`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
--  Blotter hearing scheduling columns (for automated summons/reminders)
-- ----------------------------------------------------------------------------
SET @s1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_cases' AND COLUMN_NAME = 'hearing_date');
SET @s2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_cases' AND COLUMN_NAME = 'hearing_time');
SET @s3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_cases' AND COLUMN_NAME = 'lupon_desk');

SET @sql = IF(@s1 = 0, 'ALTER TABLE blotter_cases ADD COLUMN hearing_date DATE DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@s2 = 0, 'ALTER TABLE blotter_cases ADD COLUMN hearing_time TIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(@s3 = 0, 'ALTER TABLE blotter_cases ADD COLUMN lupon_desk VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes for hearing queries / reminders
SET @idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blotter_cases' AND INDEX_NAME = 'idx_hearing');
SET @sql = IF(@idx = 0, 'ALTER TABLE blotter_cases ADD INDEX idx_hearing (hearing_date, hearing_time)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  Documentation of design
-- ----------------------------------------------------------------------------
--  ISmsService        : transport contract (adapter pattern)
--  SemaphoreSmsProvider: concrete cloud adapter (Semaphore v2)
--  SmsLogger          : repository for SmsLogs
--  SmsService         : orchestrator (sanitize, E.164, 160-char segment, retry+backoff, alert)
--  SmsTriggers        : event hooks (Document / Summons / Broadcast)
--  bin/sms-worker.php : async queue processor (run as daemon/cron)
-- ============================================================================
