<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NyscDataExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $collection;

    public function __construct($collection)
    {
        $this->collection = $collection;
    }

    public function collection()
    {
        return $this->collection;
    }

    public function headings(): array
    {
        return [
            'MatricNo',
            'FirstName',
            'Middlename',
            'Surname',
            'GSMNo',
            'StateOfOrigin',
            'ClassOfDegree',
            'DateOfBirth',
            'DateOfGraduation',
            'Status',
            'Gender',
            'MaritalStatus',
            'JambRegNo',
            'IsMilitary',
            'CourseOfStudy',
            'StudyMode',
            'Department',
            'Email',
            'Level',
            'CGPA',
            'PaymentStatus',
            'CreatedAt'
        ];
    }

    public function map($student): array
    {
        return [
            strtoupper($student->matric_no),
            ucwords(strtolower($student->fname)),
            ucwords(strtolower($student->mname)),
            ucwords(strtolower($student->lname)),
            "'" . ($student->phone ?? ''),
            ucwords(strtolower($student->state)),
            ucwords(strtolower($student->class_of_degree)),
            "'" . ($student->dob ? date('Y-m-d', strtotime($student->dob)) : ''),
            "'" . ($student->graduation_year ?? ''),
            $student->is_status === false ? 'REVALID' : 'FRESH',
            ucwords(strtolower($student->gender)),
            ucwords(strtolower($student->marital_status)),
            strtoupper($student->jamb_no),
            $student->is_military ? 'Yes' : 'No',
            ucwords(strtolower($student->course_study)),
            ucwords(strtolower($student->study_mode)),
            ucwords(strtolower($student->department)),
            $student->email,
            $student->level,
            $student->cgpa,
            $student->is_paid ? 'PAID' : 'UNPAID',
            $student->created_at ? $student->created_at->format('Y-m-d H:i:s') : ''
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
