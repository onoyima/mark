<?php

namespace App\Exports;

use App\Models\StudentNysc;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class NyscExport implements FromQuery, WithHeadings, WithMapping
{
    protected $query;

    protected bool $csvMode;

    /**
     * Zero-based indexes of columns that must keep their leading zeros /
     * exact text formatting: 5 = dob, 7 = phone, 17 = graduation_year.
     * matric_no (13) and jamb_no (19) are intentionally excluded.
     */
    protected array $textColumns = [5, 7, 17];

    public function __construct($query = null, bool $csvMode = false)
    {
        $this->query = $query ?: StudentNysc::query();
        $this->csvMode = $csvMode;
    }

    public function query()
    {
        return $this->query->select([
            'student_id', 'fname', 'lname', 'mname', 'gender',
            'dob', 'marital_status', 'phone', 'email', 'address',
            'state', 'lga', 'username', 'matric_no', 'department',
            'course_study', 'level', 'graduation_year', 'cgpa',
            'jamb_no', 'study_mode', 'is_paid', 'is_submitted', 'submitted_at'
        ]);
    }

    public function map($row): array
    {
        $values = [
            $row->student_id,
            $row->fname,
            $row->lname,
            $row->mname,
            $row->gender,
            $row->dob ? $row->dob->format('Y-m-d') : '',
            $row->marital_status,
            $row->phone,
            $row->email,
            $row->address,
            $row->state,
            $row->lga,
            $row->username,
            $row->matric_no,
            $row->department,
            $row->course_study,
            $row->level,
            $row->graduation_year,
            $row->cgpa,
            $row->jamb_no,
            $row->study_mode,
            $row->is_paid,
            $row->is_submitted,
            $row->submitted_at ? $row->submitted_at->format('Y-m-d H:i:s') : '',
        ];

        if ($this->csvMode) {
            foreach ($this->textColumns as $i) {
                if (isset($values[$i]) && $values[$i] !== '' && $values[$i] !== null) {
                    $values[$i] = '\'' . $values[$i];
                }
            }
        }

        return $values;
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
