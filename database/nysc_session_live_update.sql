-- ============================================================
-- NYSC Session Tracking - Universal Live DB Update Script
-- Compatible with: MySQL 5.7+ / MySQL 8.0+ / MariaDB
-- ============================================================
-- IMPORTANT: This script is designed to be safe for live databases.
-- It uses a stored procedure to handle situations where columns
-- or indexes might already exist, preventing error halts.
-- ============================================================

-- -------------------------------------------------------
-- 1. Create `nysc_sessions` table (Standard SQL)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nysc_sessions` (
    `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255)        NOT NULL,
    `code`       VARCHAR(50)         NULL         DEFAULT NULL,
    `start_at`   DATETIME            NOT NULL,
    `end_at`     DATETIME            NULL         DEFAULT NULL,
    `is_active`  TINYINT(1)          NOT NULL     DEFAULT 0,
    `status`     VARCHAR(50)         NULL         DEFAULT 'open',
    `created_at` TIMESTAMP           NULL         DEFAULT NULL,
    `updated_at` TIMESTAMP           NULL         DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `nysc_sessions_code_unique` (`code`),
    KEY `nysc_sessions_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. Define a procedure to safely apply ALTERs
-- -------------------------------------------------------
DROP PROCEDURE IF EXISTS `_nysc_apply_safety_updates`;

DELIMITER //

CREATE PROCEDURE `_nysc_apply_safety_updates`()
BEGIN
    -- [HANDLER] 1060 = Duplicate column name
    -- [HANDLER] 1061 = Duplicate key name
    -- [HANDLER] 1022 = Duplicate key in table (some constraints)
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1022 BEGIN END;

    -- ---- student_nysc updates ----
    ALTER TABLE `student_nysc` ADD COLUMN `nysc_session_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `id`;
    ALTER TABLE `student_nysc` ADD KEY `student_nysc_session_fk_index` (`nysc_session_id`);
    ALTER TABLE `student_nysc` ADD CONSTRAINT `student_nysc_session_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

    -- ---- nysc_payments updates ----
    ALTER TABLE `nysc_payments` ADD COLUMN `nysc_session_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `student_nysc_id`;
    ALTER TABLE `nysc_payments` ADD KEY `nysc_payments_session_fk_index` (`nysc_session_id`);
    ALTER TABLE `nysc_payments` ADD CONSTRAINT `nysc_payments_session_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

    -- ---- nysc_temp_submissions updates ----
    ALTER TABLE `nysc_temp_submissions` ADD COLUMN `nysc_session_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `student_id`;
    ALTER TABLE `nysc_temp_submissions` ADD KEY `nysc_temp_session_fk_index` (`nysc_session_id`);
    ALTER TABLE `nysc_temp_submissions` ADD CONSTRAINT `nysc_temp_session_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

END //

DELIMITER ;

-- -------------------------------------------------------
-- 3. Execute and Clean up
-- -------------------------------------------------------
CALL `_nysc_apply_safety_updates`();
DROP PROCEDURE IF EXISTS `_nysc_apply_safety_updates`;

-- -------------------------------------------------------
-- 4. Final verification view
-- -------------------------------------------------------
SELECT 'nysc_sessions' AS `table`;
DESCRIBE `nysc_sessions`;
SELECT 'student_nysc' AS `table`;
DESCRIBE `student_nysc`;
SELECT 'nysc_payments' AS `table`;
DESCRIBE `nysc_payments`;
SELECT 'nysc_temp_submissions' AS `table`;
DESCRIBE `nysc_temp_submissions`;
