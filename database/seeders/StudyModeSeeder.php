<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\StudyMode;

class StudyModeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $studyModes = [
            ['id' => 1, 'mode' => 'Full-time', 'status' => 1],
            ['id' => 2, 'mode' => 'Part-time', 'status' => 1],
            ['id' => 3, 'mode' => 'Distance', 'status' => 1],
        ];

        // Insert default study modes
        DB::table('study_modes')->insert($studyModes);
    }
}