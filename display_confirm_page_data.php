<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\StudentContact;
use App\Models\StudentMedical;
use App\Models\State;
use App\Models\StudyMode;
use App\Models\Department;

echo "=== CONFIRM PAGE FORM DATA DISPLAY ===\n\n";

// Get test student data (ID 1336)
$studentId = 1336;
$student = Student::find($studentId);

if (!$student) {
    echo "❌ Student with ID $studentId not found.\n";
    exit(1);
}

// Get related data
$academic = StudentAcademic::where('student_id', $studentId)->first();
$contact = StudentContact::where('student_id', $studentId)->first();
$medical = StudentMedical::where('student_id', $studentId)->first();

echo "Testing with Student: {$student->fname} {$student->lname} (ID: $studentId)\n\n";

echo "=== CONFIRM PAGE FORM FIELDS ===\n\n";

// Personal Information Section
echo "--- PERSONAL INFORMATION ---\n";
echo "First Name: " . ($student->fname ?? 'Not provided') . "\n";
echo "Middle Name: " . ($student->mname ?? 'Not provided') . "\n";
echo "Last Name: " . ($student->lname ?? 'Not provided') . "\n";
echo "Gender: " . ($student->gender ?? 'Not provided') . "\n";
echo "Date of Birth: " . ($student->dob ?? 'Not provided') . "\n";
echo "Marital Status: " . ($student->marital_status ?? 'Not provided') . "\n";
echo "Religion: " . ($student->religion ?? 'Not provided') . "\n";
echo "State of Origin: " . ($student->state->name ?? 'Not provided') . "\n";
echo "LGA: " . ($student->lga_name ?? 'Not provided') . "\n";

// Contact Information Section
echo "\n--- CONTACT INFORMATION ---\n";
echo "Phone Number: " . ($student->phone ?? 'Not provided') . "\n";
echo "Email Address: " . ($student->username ?? 'Not provided') . "\n";
echo "Home Address: " . ($student->address ?? 'Not provided') . "\n";

// Academic Information Section
echo "\n--- ACADEMIC INFORMATION ---\n";
echo "Matriculation Number: " . ($academic->matric_no ?? 'Not provided') . "\n";
echo "Course of Study ID: " . ($academic->course_study_id ?? 'Not provided') . "\n";
echo "Department: " . ($academic->department->name ?? 'Not provided') . "\n";
echo "Faculty ID: " . ($academic->faculty_id ?? 'Not provided') . "\n";
echo "JAMB Number: " . ($academic->jambno ?? 'Not provided') . "\n";
echo "Study Mode: " . ($academic->studyMode->mode ?? 'Not provided') . "\n";
echo "Level: " . ($academic->level ?? 'Not provided') . "\n";
echo "Session: " . ($academic->session ?? 'Not provided') . "\n";
echo "Graduation Year: " . ($academic->graduation_year ?? 'Not provided') . "\n";
echo "CGPA: " . ($academic->cgpa ?? 'Not provided') . "\n";

// Emergency Contact Information
echo "\n--- EMERGENCY CONTACT ---\n";
if ($contact) {
    echo "Title: " . ($contact->emergency_contact_title ?? 'Not provided') . "\n";
    echo "Name: " . ($contact->emergency_contact_name ?? 'Not provided') . "\n";
    echo "Other Names: " . ($contact->emergency_contact_other_names ?? 'Not provided') . "\n";
    echo "Relationship: " . ($contact->emergency_contact_relationship ?? 'Not provided') . "\n";
    echo "Address: " . ($contact->emergency_contact_address ?? 'Not provided') . "\n";
    echo "Phone: " . ($contact->emergency_contact_phone ?? 'Not provided') . "\n";
    echo "Email: " . ($contact->emergency_contact_email ?? 'Not provided') . "\n";
} else {
    echo "No emergency contact information found\n";
}

// Medical Information
echo "\n--- MEDICAL INFORMATION ---\n";
if ($medical) {
    echo "Blood Group: " . ($medical->blood_group ?? 'Not provided') . "\n";
    echo "Genotype: " . ($medical->genotype ?? 'Not provided') . "\n";
    echo "Physical Condition: " . ($medical->physical ?? 'Not provided') . "\n";
    echo "Medical Condition: " . ($medical->condition ?? 'Not provided') . "\n";
    echo "Allergies: " . ($medical->allergies ?? 'Not provided') . "\n";
} else {
    echo "No medical information found\n";
}

echo "\n=== DATA MAPPING FOR PAYMENT PAGE ===\n\n";

// Show exactly what should be sent to the payment page
$confirmPageData = [
    // Personal Information
    'fname' => $student->fname,
    'mname' => $student->mname,
    'lname' => $student->lname,
    'gender' => strtolower($student->gender ?? ''),
    'dob' => $student->dob,
    'marital_status' => strtolower($student->marital_status ?? ''),
    'religion' => $student->religion,
    'state_of_origin' => $student->state->name ?? null,
    'lga' => $student->lga_name,

    // Contact Information
    'phone' => $student->phone,
    'email' => $student->username,
    'address' => $student->address,

    // Academic Information
    'matric_no' => $academic->matric_no ?? null,
    'course_of_study' => $academic->course_study_id ?? null,
    'department' => $academic->department->name ?? null,
    'faculty' => $academic->faculty_id ?? null,
    'jambno' => $academic->jambno ?? null,
    'study_mode' => $academic->studyMode->mode ?? null,
    'level' => $academic->level ?? null,
    'session' => $academic->session ?? null,
    'graduation_year' => $academic->graduation_year ?? null,
    'cgpa' => $academic->cgpa ?? null,

    // Emergency Contact
    'emergency_contact_title' => $contact->emergency_contact_title ?? null,
    'emergency_contact_name' => $contact->emergency_contact_name ?? null,
    'emergency_contact_other_names' => $contact->emergency_contact_other_names ?? null,
    'emergency_contact_relationship' => $contact->emergency_contact_relationship ?? null,
    'emergency_contact_address' => $contact->emergency_contact_address ?? null,
    'emergency_contact_phone' => $contact->emergency_contact_phone ?? null,
    'emergency_contact_email' => $contact->emergency_contact_email ?? null,

    // Medical Information
    'blood_group' => $medical->blood_group ?? null,
    'genotype' => $medical->genotype ?? null,
    'physical_condition' => $medical->physical ?? null,
    'medical_condition' => $medical->condition ?? null,
    'allergies' => $medical->allergies ?? null,
];

echo "--- JSON DATA TO BE SENT TO PAYMENT PAGE ---\n";
echo json_encode($confirmPageData, JSON_PRETTY_PRINT) . "\n";

echo "\n=== FIELDS THAT SHOULD BE STORED IN student_nysc TABLE ===\n\n";

// Fields that exist in student_nysc table (based on migration)
$nyscTableFields = [
    'fname',
    'lname',
    'mname',
    'gender',
    'dob',
    'marital_status',
    'phone',
    'email',
    'address',
    'state_of_origin',
    'lga',
    'matric_no',
    'course_of_study',
    'department',
    'faculty',
    'graduation_year',
    'cgpa',
    'jambno',
    'study_mode',
    'emergency_contact_name',
    'emergency_contact_phone',
    'emergency_contact_relationship',
    'emergency_contact_address',
    'emergency_contact_email'
];

foreach ($nyscTableFields as $field) {
    $value = $confirmPageData[$field] ?? 'NULL';
    echo "$field: $value\n";
}

echo "\n=== FIELDS NOT IN student_nysc TABLE (SHOULD BE EXCLUDED) ===\n\n";

$excludedFields = [
    'religion',
    'level',
    'session',
    'emergency_contact_title',
    'emergency_contact_other_names',
    'blood_group',
    'genotype',
    'physical_condition',
    'medical_condition',
    'allergies'
];

foreach ($excludedFields as $field) {
    $value = $confirmPageData[$field] ?? 'NULL';
    echo "$field: $value (EXCLUDED)\n";
}

echo "\n=== ANALYSIS COMPLETE ===\n";
