<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StudentNyscExport implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    protected $students;

    public function __construct($students)
    {
        $this->students = $students;
    }

    public function array(): array
    {
        $data = [];
        
        foreach ($this->students as $student) {
            $data[] = [
                $student->id ?? '',
                $student->fname ?? '',
                $student->mname ?? '',
                $student->lname ?? '',
                $student->matric_no ?? '',
                $student->jamb_no ?? '',
                $student->study_mode ?? '',
                $student->gender ?? '',
                $student->dob ? $student->dob->format('Y-m-d') : '',
                $student->marital_status ?? '',
                $student->phone ?? '',
                $student->email ?? '',
                $student->address ?? '',
                $student->state ?? '',
                $student->lga ?? '',
                $student->course_study ?? '',
                $student->department ?? '',
                $student->graduation_year ?? '',
                $student->cgpa ?? '',
                $student->class_of_degree ?? '',
                $student->is_paid ? 'Paid' : 'Unpaid',
                $student->payment_amount ?? 0,
                $student->payment_date ? $student->payment_date->format('Y-m-d H:i:s') : '',
            ];
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Middle Name',
            'Last Name',
            'Matric No',
            'JAMB No',
            'Study Mode',
            'Gender',
            'Date of Birth',
            'Marital Status',
            'Phone',
            'Email',
            'Address',
            'State of Origin',
            'LGA',
            'Course of Study',
            'Department',
            'Graduation Year',
            'CGPA',
            'Class of Degree',
            'Payment Status',
            'Payment Amount',
            'Payment Date',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 10,
            'B' => 15,
            'C' => 15,
            'D' => 15,
            'E' => 15,
            'F' => 15,
            'G' => 12,
            'H' => 10,
            'I' => 12,
            'J' => 12,
            'K' => 15,
            'L' => 25,
            'M' => 30,
            'N' => 15,
            'O' => 15,
            'P' => 25,
            'Q' => 20,
            'R' => 12,
            'S' => 10,
            'T' => 15,
            'U' => 12,
            'V' => 12,
            'W' => 20,
        ];
    }
}