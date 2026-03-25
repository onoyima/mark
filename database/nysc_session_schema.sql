-- Run these SQL commands in your database to create the NYSC Sessions table 
-- and update the existing NYSC tables to support session tracking.

-- 1. Create the nysc_sessions table
CREATE TABLE `nysc_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nysc_sessions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add nysc_session_id to student_nysc
ALTER TABLE `student_nysc`
ADD COLUMN `nysc_session_id` bigint(20) unsigned DEFAULT NULL AFTER `student_id`;

ALTER TABLE `student_nysc`
ADD CONSTRAINT `student_nysc_nysc_session_id_foreign` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL;

-- 3. Add nysc_session_id to nysc_payments
ALTER TABLE `nysc_payments`
ADD COLUMN `nysc_session_id` bigint(20) unsigned DEFAULT NULL AFTER `student_nysc_id`;

ALTER TABLE `nysc_payments`
ADD CONSTRAINT `nysc_payments_nysc_session_id_foreign` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL;

-- 4. Add nysc_session_id to nysc_temp_submissions
ALTER TABLE `nysc_temp_submissions`
ADD COLUMN `nysc_session_id` bigint(20) unsigned DEFAULT NULL AFTER `student_id`;

ALTER TABLE `nysc_temp_submissions`
ADD CONSTRAINT `nysc_temp_submissions_nysc_session_id_foreign` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions` (`id`) ON DELETE SET NULL;

-- Note: Existing data will automatically carry a NULL value for nysc_session_id, 
-- meeting your requirement that existing data carries the current session or null.
