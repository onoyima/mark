<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class StudentNyscExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $students;

    public function __construct($students)
    {
        $this->students = $students;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->students;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'S/N',
            'Student ID',
            'Matric Number',
            'First Name',
            'Middle Name',
            'Last Name',
            'Email',
            'Phone',
            'Gender',
            'Date of Birth',
            'Address',
            'State',
            'LGA',
            'Department',
            'Course of Study',
            'Level',
            'CGPA',
            'Graduation Year',
            'JAMB Number',
            'Study Mode',
            'Payment Status',
            'Payment Amount',
            'Payment Date',
            'Submission Status',
            'Registration Date',
            'Last Updated'
        ];
    }

    /**
     * @param mixed $student
     * @return array
     */
    public function map($student): array
    {
        static $counter = 0;
        $counter++;
        
        $paymentAmount = $student->payments->first()?->amount ?? 0;
        $paymentDate = $student->payments->first()?->payment_date;
        
        return [
            $counter,
            $student->student_id,
            $student->matric_no,
            $student->fname,
            $student->mname,
            $student->lname,
            $student->email,
            $student->phone,
            ucfirst($student->gender ?? ''),
            $student->dob ? \Carbon\Carbon::parse($student->dob)->format('Y-m-d') : '',
            $student->address,
            $student->state,
            $student->lga,
            $student->department,
            $student->course_study,
            $student->level,
            $student->cgpa,
            $student->graduation_year,
            $student->jamb_no,
            $student->study_mode,
            $student->is_paid ? 'Paid' : 'Unpaid',
            $paymentAmount ? '₦' . number_format($paymentAmount, 2) : '',
            $paymentDate ? \Carbon\Carbon::parse($paymentDate)->format('Y-m-d H:i:s') : '',
            $student->is_submitted ? 'Submitted' : 'Not Submitted',
            $student->created_at ? $student->created_at->format('Y-m-d H:i:s') : '',
            $student->updated_at ? $student->updated_at->format('Y-m-d H:i:s') : ''
        ];
    }

    /**
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as header
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
            // Style all cells
            'A:Z' => [
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ],
        ];
    }
}