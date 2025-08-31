<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\StudentContact;
use Illuminate\Support\Facades\Hash;

// Create the test student
$student = Student::create([
    'title_id' => 1,
    'fname' => 'BONIFACE',
    'mname' => 'MIDDLE',
    'lname' => 'ONOYIMA',
    'gender' => 'male',
    'dob' => '1995-01-15',
    'country_id' => 1,
    'state_id' => 25, // Lagos
    'lga_name' => 'Ikeja',
    'city' => 'Lagos',
    'religion' => 'Christianity',
    'marital_status' => 'single',
    'address' => '123 Test Street, Lagos',
    'phone' => '08012345678',
    'email' => 'boniface.onoyima@example.com',
    'username' => 'vug/csc/16/1336',
    'password' => Hash::make('welcome'),
    'status' => 'active'
]);

echo "Student created with ID: " . $student->id . "\n";

// Create academic record
$academic = StudentAcademic::create([
    'student_id' => $student->id,
    'matric_no' => 'vug/csc/16/1336',
    'course_study_id' => 1,
    'department_id' => 1,
    'faculty_id' => 1,
    'level' => 400,
    'entry_session_id' => 1,
    'academic_session_id' => 1,
    'jamb_no' => '12345678AB',
    'study_mode_id' => 1 // Full-time
]);

echo "Academic record created\n";

// Create contact record
$contact = StudentContact::create([
    'student_id' => $student->id,
    'title' => 'Mr.',
    'surname' => 'Onoyima',
    'other_names' => 'Parent Name',
    'relationship' => 'parent',
    'address' => '456 Parent Street, Lagos',
    'state' => 'Lagos',
    'city' => 'Lagos',
    'phone_no' => '08087654321',
    'phone_no_two' => '08098765432',
    'email' => 'parent@example.com'
]);

echo "Contact record created\n";
echo "Test user setup complete!\n";
echo "Username: vug/csc/16/1336\n";
echo "Password: welcome\n";