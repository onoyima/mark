<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplySessionSchema extends Command
{
    protected $signature = 'nysc:apply-session-schema';
    protected $description = 'Apply NYSC session table and column updates individually';

    public function handle()
    {
        if (!Schema::hasTable('nysc_sessions')) {
            DB::statement("CREATE TABLE `nysc_sessions` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL, `code` VARCHAR(50) NULL, `start_at` TIMESTAMP NOT NULL, `end_at` TIMESTAMP NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 0, `status` VARCHAR(50) NULL, `created_at` TIMESTAMP NULL DEFAULT NULL, `updated_at` TIMESTAMP NULL DEFAULT NULL, PRIMARY KEY (`id`), KEY `nysc_sessions_is_active_idx` (`is_active`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }

        $row = DB::table('nysc_sessions')->where('id', 1)->first();
        if (!$row) {
            DB::table('nysc_sessions')->insert(['id' => 1, 'name' => 'Default Session', 'code' => 'S001', 'start_at' => now(), 'end_at' => null, 'is_active' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('nysc_sessions')->where('id', 1)->update(['is_active' => 1, 'status' => $row->status ?? 'open']);
        }

        $hasActiveId = DB::table('admin_settings')->where('key', 'active_session_id')->exists();
        if (!$hasActiveId) {
            DB::table('admin_settings')->insert(['key' => 'active_session_id', 'value' => '1', 'type' => 'number', 'description' => 'Active NYSC session id', 'category' => 'sessions', 'is_active' => 1]);
        } else {
            DB::table('admin_settings')->where('key', 'active_session_id')->update(['value' => '1', 'type' => 'number', 'description' => 'Active NYSC session id', 'category' => 'sessions', 'is_active' => 1]);
        }
        $hasActiveName = DB::table('admin_settings')->where('key', 'active_session_name')->exists();
        if (!$hasActiveName) {
            DB::table('admin_settings')->insert(['key' => 'active_session_name', 'value' => 'Default Session', 'type' => 'string', 'description' => 'Active NYSC session name', 'category' => 'sessions', 'is_active' => 1]);
        } else {
            DB::table('admin_settings')->where('key', 'active_session_name')->update(['value' => 'Default Session', 'type' => 'string', 'description' => 'Active NYSC session name', 'category' => 'sessions', 'is_active' => 1]);
        }

        if (Schema::hasTable('nysc_temp_submissions')) {
            if (!Schema::hasColumn('nysc_temp_submissions', 'submission_token')) {
                DB::statement("ALTER TABLE `nysc_temp_submissions` ADD COLUMN `submission_token` VARCHAR(255) NULL AFTER `student_id`");
            }
            if (!Schema::hasColumn('nysc_temp_submissions', 'nysc_session_id')) {
                DB::statement("ALTER TABLE `nysc_temp_submissions` ADD COLUMN `nysc_session_id` BIGINT UNSIGNED NULL AFTER `submission_token`");
                DB::statement("ALTER TABLE `nysc_temp_submissions` ADD KEY `nysc_temp_submissions_nysc_session_id_idx` (`nysc_session_id`)");
            }
            if (Schema::hasColumn('nysc_temp_submissions', 'session_id')) {
                DB::statement("UPDATE `nysc_temp_submissions` SET `submission_token` = `session_id` WHERE `submission_token` IS NULL AND `session_id` IS NOT NULL");
            }
            DB::statement("UPDATE `nysc_temp_submissions` SET `nysc_session_id` = 1 WHERE `nysc_session_id` IS NULL");
            $hasLegacyIndex = false;
            $indexes = DB::select("SHOW INDEX FROM `nysc_temp_submissions`");
            foreach ($indexes as $idx) { if (($idx->Key_name ?? '') === 'nysc_temp_submissions_session_id_unique' || (($idx->Key_name ?? '') === 'session_id' && ($idx->Non_unique ?? 1) == 0)) { $hasLegacyIndex = true; break; } }
            if ($hasLegacyIndex) { DB::statement("ALTER TABLE `nysc_temp_submissions` DROP INDEX `nysc_temp_submissions_session_id_unique`"); }
            if (Schema::hasColumn('nysc_temp_submissions', 'session_id')) { DB::statement("ALTER TABLE `nysc_temp_submissions` DROP COLUMN `session_id`"); }
            DB::statement("UPDATE `nysc_temp_submissions` SET `submission_token` = CONCAT('NYSC-TEMP-', UUID()) WHERE `submission_token` IS NULL OR `submission_token` = '' OR `submission_token` = '0'");
            DB::statement("UPDATE `nysc_temp_submissions` nts JOIN (SELECT submission_token FROM nysc_temp_submissions GROUP BY submission_token HAVING COUNT(*)>1) dup ON dup.submission_token = nts.submission_token SET nts.submission_token = CONCAT('NYSC-TEMP-', UUID())");
            $hasTokenIndex = false;
            $indexes2 = DB::select("SHOW INDEX FROM `nysc_temp_submissions`");
            foreach ($indexes2 as $idx) { if (($idx->Key_name ?? '') === 'nysc_temp_submissions_submission_token_unique') { $hasTokenIndex = true; break; } }
            if (!$hasTokenIndex) { DB::statement("ALTER TABLE `nysc_temp_submissions` ADD UNIQUE KEY `nysc_temp_submissions_submission_token_unique` (`submission_token`)"); }
            $fkExists = false;
            $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nysc_temp_submissions' AND REFERENCED_TABLE_NAME IS NOT NULL");
            foreach ($fks as $fk) { if (($fk->CONSTRAINT_NAME ?? '') === 'nysc_temp_submissions_nysc_session_id_fk') { $fkExists = true; break; } }
            if (!$fkExists) { DB::statement("ALTER TABLE `nysc_temp_submissions` ADD CONSTRAINT `nysc_temp_submissions_nysc_session_id_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions`(`id`) ON DELETE SET NULL"); }
        }

        if (Schema::hasTable('nysc_payments')) {
            if (!Schema::hasColumn('nysc_payments', 'nysc_session_id')) {
                DB::statement("ALTER TABLE `nysc_payments` ADD COLUMN `nysc_session_id` BIGINT UNSIGNED NULL AFTER `student_nysc_id`");
                DB::statement("ALTER TABLE `nysc_payments` ADD KEY `nysc_payments_nysc_session_id_idx` (`nysc_session_id`)");
            }
            if (!Schema::hasColumn('nysc_payments', 'submission_token')) {
                DB::statement("ALTER TABLE `nysc_payments` ADD COLUMN `submission_token` VARCHAR(255) NULL AFTER `nysc_session_id`");
                DB::statement("ALTER TABLE `nysc_payments` ADD KEY `nysc_payments_submission_token_idx` (`submission_token`)");
            }
            DB::statement("UPDATE `nysc_payments` SET `nysc_session_id` = 1 WHERE `nysc_session_id` IS NULL");
            $indexes = DB::select("SHOW INDEX FROM `nysc_payments`");
            foreach ($indexes as $idx) { if (($idx->Column_name ?? '') === 'session_id') { DB::statement("ALTER TABLE `nysc_payments` DROP INDEX `{$idx->Key_name}`"); } }
            if (Schema::hasColumn('nysc_payments', 'session_id')) { DB::statement("ALTER TABLE `nysc_payments` DROP COLUMN `session_id`"); }
            $fkExists = false;
            $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nysc_payments' AND REFERENCED_TABLE_NAME IS NOT NULL");
            foreach ($fks as $fk) { if (($fk->CONSTRAINT_NAME ?? '') === 'nysc_payments_nysc_session_id_fk') { $fkExists = true; break; } }
            if (!$fkExists) { DB::statement("ALTER TABLE `nysc_payments` ADD CONSTRAINT `nysc_payments_nysc_session_id_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions`(`id`) ON DELETE SET NULL"); }
        }

        if (Schema::hasTable('student_nysc')) {
            if (!Schema::hasColumn('student_nysc', 'nysc_session_id')) {
                DB::statement("ALTER TABLE `student_nysc` ADD COLUMN `nysc_session_id` BIGINT UNSIGNED NULL AFTER `student_id`");
                DB::statement("ALTER TABLE `student_nysc` ADD KEY `student_nysc_nysc_session_id_idx` (`nysc_session_id`)");
            }
            $fkExists = false;
            $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_nysc' AND REFERENCED_TABLE_NAME IS NOT NULL");
            foreach ($fks as $fk) { if (($fk->CONSTRAINT_NAME ?? '') === 'student_nysc_nysc_session_id_fk') { $fkExists = true; break; } }
            if (!$fkExists) { DB::statement("ALTER TABLE `student_nysc` ADD CONSTRAINT `student_nysc_nysc_session_id_fk` FOREIGN KEY (`nysc_session_id`) REFERENCES `nysc_sessions`(`id`) ON DELETE SET NULL"); }

            DB::statement("UPDATE `student_nysc` sn JOIN (SELECT student_id, MAX(id) AS max_id FROM nysc_payments WHERE status='successful' GROUP BY student_id) mp ON sn.student_id = mp.student_id JOIN nysc_payments p ON p.id = mp.max_id SET sn.nysc_session_id = p.nysc_session_id WHERE sn.nysc_session_id IS NULL");
        }

        $this->info('Applied session schema changes');
        return 0;
    }
}