-- ============================================================
-- NERD TABLE (separate from student_nysc)
-- Safe to import directly into the live database (dbinv2oggorg69).
-- Creates a NEW table "student_nerds" - does NOT alter student_nysc.
--
-- Steps:
--   1. Import this file (phpMyAdmin / mysql CLI / Navicat)
--   2. Optionally verify: SELECT COUNT(*) FROM student_nerds;
-- ============================================================

-- ------------------------------------------------------------
-- 1. Create the nerd table (fails safely if it already exists)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_nerds` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` bigint(20) UNSIGNED NOT NULL,
    `nysc_session_id` bigint(20) UNSIGNED NULL,
    `matric_no` varchar(50) COLLATE utf8mb4_unicode_ci NULL,
    `nin` varchar(20) COLLATE utf8mb4_unicode_ci NULL,
    `email` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `phone` varchar(20) COLLATE utf8mb4_unicode_ci NULL,
    `fname` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `mname` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `lname` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `gender` varchar(10) COLLATE utf8mb4_unicode_ci NULL,
    `dob` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `state` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `course_study` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `study_mode` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `department` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `cgpa` decimal(5,2) NULL,
    `class_of_degree` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `graduation_year` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `graduation_date` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    INDEX `student_nerds_matric_no_index` (`matric_no`),
    INDEX `student_nerds_nysc_session_id_index` (`nysc_session_id`),
    INDEX `student_nerds_student_id_index` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Backfill existing student_nysc records into the nerd table
--    (idempotent - will not duplicate rows for the same student)
-- ------------------------------------------------------------
INSERT INTO `student_nerds`
    (`student_id`, `nysc_session_id`, `matric_no`, `nin`, `email`, `phone`,
     `fname`, `mname`, `lname`, `gender`, `dob`, `state`,
     `course_study`, `study_mode`, `department`, `cgpa`,
     `class_of_degree`, `graduation_year`, `graduation_date`,
     `created_at`, `updated_at`)
SELECT
    sn.`student_id`,
    sn.`nysc_session_id`,
    sn.`matric_no`,
    sn.`nin`,
    sn.`email`,
    sn.`phone`,
    sn.`fname`,
    sn.`mname`,
    sn.`lname`,
    sn.`gender`,
    CASE
        WHEN sn.`dob` IS NULL THEN NULL
        ELSE DATE_FORMAT(sn.`dob`, '%Y-%m-%d')
    END AS `dob`,
    sn.`state`,
    sn.`course_study`,
    sn.`study_mode`,
    sn.`department`,
    CASE
        WHEN sn.`cgpa` IS NULL THEN NULL
        ELSE ROUND(sn.`cgpa`, 2)
    END AS `cgpa`,
    sn.`class_of_degree`,
    sn.`graduation_year`,
    NULL AS `graduation_date`,
    NOW() AS `created_at`,
    NOW() AS `updated_at`
FROM `student_nysc` sn
WHERE NOT EXISTS (
    SELECT 1 FROM `student_nerds` nd WHERE nd.`student_id` = sn.`student_id`
);

-- ------------------------------------------------------------
-- 3. Verification query (run after import if desired)
--    SELECT COUNT(*) AS total_nerds FROM student_nerds;
--    SELECT * FROM student_nerds LIMIT 10;
-- ------------------------------------------------------------