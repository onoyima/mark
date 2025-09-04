<?php

namespace App\Exports;

use App\Models\StudentNysc;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class NyscExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return StudentNysc::select([
            'student_id', 'fname', 'lname', 'mname', 'gender',
            'dob', 'marital_status', 'phone', 'email', 'address',
            'state', 'lga', 'username', 'matric_no', 'department',
            'course_study', 'level', 'graduation_year', 'cgpa',
            'jamb_no', 'study_mode', 'is_paid', 'is_submitted', 'submitted_at'
        ])->get();
    }

    public function headings(): array
    {
        return [
            'Student ID', 'First Name', 'Last Name', 'Middle Name', 'Gender',
            'Date of Birth', 'Marital Status', 'Phone', 'Email', 'Address',
            'State', 'LGA', 'Username', 'Matric No', 'Department',
            'Course of Study', 'Level', 'Graduation Year', 'CGPA',
            'JAMB No', 'Study Mode', 'Is Paid', 'Is Submitted', 'Submitted At'
        ];
    }
}
