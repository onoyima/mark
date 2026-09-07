-- ============================================================
-- NERD TABLE (separate from student_nysc)
-- Safe to import directly into the live database (dbinv2oggorg69).
-- Creates a NEW table "student_nerds" - does NOT alter student_nysc.
--
-- This schema is the FINAL canonical column set. If you already have an
-- older student_nerds (email/phone/fname/...) run the migrations instead
-- (php artisan migrate): 2026_09_07_131354_add_award_fields... and
-- 2026_09_07_140000_canonicalize_student_nerds_columns.php rename columns
-- in-place (data preserved) and add the new ones.
--
-- Steps (fresh environment only):
--   1. Import this file (phpMyAdmin / mysql CLI / Navicat)
--   2. Populate the award fields:
--        php database/sql/backfill_student_nerds_awards.php
--   3. Optionally verify: SELECT COUNT(*) FROM student_nerds;
-- ============================================================

-- ------------------------------------------------------------
-- 1. Create the nerd table (fails safely if it already exists)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_nerds` (
    `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id` bigint(20) UNSIGNED NOT NULL,
    `nysc_session_id` bigint(20) UNSIGNED NULL,
    `nin` varchar(20) COLLATE utf8mb4_unicode_ci NULL,
    `matric_no` varchar(50) COLLATE utf8mb4_unicode_ci NULL,
    `student_email` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `phone_number` varchar(20) COLLATE utf8mb4_unicode_ci NULL,
    `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `surname` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `sex` varchar(10) COLLATE utf8mb4_unicode_ci NULL,
    `date_of_birth` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `state` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `programme_major` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `award_title` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `award_short_title` varchar(50) COLLATE utf8mb4_unicode_ci NULL,
    `programme_award_combined` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `programme_category` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `programme_type` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `class_of_degree_text` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `final_cgpa` decimal(5,2) NULL,
    `graduation_session` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `graduation_date` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `grade_approval_date` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `admission_date` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `mode_of_entry` varchar(100) COLLATE utf8mb4_unicode_ci NULL,
    `faculty_name` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `department_name` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `senate_meeting_ref` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `graduate_list_ref` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `verified_by` varchar(255) COLLATE utf8mb4_unicode_ci NULL,
    `remarks` text COLLATE utf8mb4_unicode_ci NULL,
    `created_at` timestamp NULL,
    `updated_at` timestamp NULL,
    PRIMARY KEY (`id`),
    INDEX `student_nerds_matric_no_index` (`matric_no`),
    INDEX `student_nerds_nysc_session_id_index` (`nysc_session_id`),
    INDEX `student_nerds_student_id_index` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Backfill existing student_nysc records into the nerd table
--    (idempotent - will not duplicate rows for the same student;
--     maps the OLD column names onto the canonical nerd columns)
-- ------------------------------------------------------------
INSERT INTO `student_nerds`
    (`student_id`, `nysc_session_id`, `matric_no`, `nin`, `student_email`,
     `phone_number`, `first_name`, `middle_name`, `surname`, `sex`,
     `date_of_birth`, `state`, `programme_major`, `programme_type`,
     `department_name`, `final_cgpa`, `class_of_degree_text`,
     `graduation_session`, `graduation_date`, `created_at`, `updated_at`)
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
    END AS `date_of_birth`,
    sn.`state`,
    sn.`course_study`,
    sn.`study_mode`,
    sn.`department`,
    CASE
        WHEN sn.`cgpa` IS NULL THEN NULL
        ELSE ROUND(sn.`cgpa`, 2)
    END AS `final_cgpa`,
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