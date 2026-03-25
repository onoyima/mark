<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckSessionSchema extends Command
{
    protected $signature = 'nysc:check-session-schema';
    protected $description = 'Verify NYSC session schema and data integrity';

    public function handle()
    {
        $existsSessions = Schema::hasTable('nysc_sessions');
        $activeSessionId = null;
        $activeSessionName = null;
        $activeSessionIsActive = null;
        if ($existsSessions) {
            $row = DB::table('nysc_sessions')->where('id', 1)->first();
            if ($row) {
                $activeSessionId = $row->id;
                $activeSessionName = $row->name;
                $activeSessionIsActive = (int)($row->is_active ?? 0);
            }
        }

        $tempHasToken = Schema::hasColumn('nysc_temp_submissions', 'submission_token');
        $tempHasSessionFk = Schema::hasColumn('nysc_temp_submissions', 'nysc_session_id');
        $tempHasLegacySession = Schema::hasColumn('nysc_temp_submissions', 'session_id');
        $tempCountSession1 = ($tempHasSessionFk && Schema::hasTable('nysc_temp_submissions'))
            ? DB::table('nysc_temp_submissions')->where('nysc_session_id', 1)->count()
            : null;

        $payHasSessionFk = Schema::hasColumn('nysc_payments', 'nysc_session_id');
        $payHasToken = Schema::hasColumn('nysc_payments', 'submission_token');
        $payHasLegacySession = Schema::hasColumn('nysc_payments', 'session_id');
        $payCountSession1 = ($payHasSessionFk && Schema::hasTable('nysc_payments'))
            ? DB::table('nysc_payments')->where('nysc_session_id', 1)->count()
            : null;

        $snHasSessionFk = Schema::hasColumn('student_nysc', 'nysc_session_id');
        $snCountSession1 = ($snHasSessionFk && Schema::hasTable('student_nysc'))
            ? DB::table('student_nysc')->where('nysc_session_id', 1)->count()
            : null;

        $adminActiveId = DB::table('admin_settings')->where('key', 'active_session_id')->value('value');
        $adminActiveName = DB::table('admin_settings')->where('key', 'active_session_name')->value('value');

        $result = [
            'nysc_sessions_table_exists' => $existsSessions,
            'active_session' => [
                'id' => $activeSessionId,
                'name' => $activeSessionName,
                'is_active' => $activeSessionIsActive,
            ],
            'admin_settings' => [
                'active_session_id' => $adminActiveId,
                'active_session_name' => $adminActiveName,
            ],
            'nysc_temp_submissions' => [
                'has_submission_token' => $tempHasToken,
                'has_nysc_session_id' => $tempHasSessionFk,
                'has_legacy_session_id' => $tempHasLegacySession,
                'count_session_id_1' => $tempCountSession1,
            ],
            'nysc_payments' => [
                'has_nysc_session_id' => $payHasSessionFk,
                'has_submission_token' => $payHasToken,
                'has_legacy_session_id' => $payHasLegacySession,
                'count_session_id_1' => $payCountSession1,
            ],
            'student_nysc' => [
                'has_nysc_session_id' => $snHasSessionFk,
                'count_session_id_1' => $snCountSession1,
            ],
        ];

        $this->line(json_encode($result));
        return 0;
    }
}