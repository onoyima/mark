<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StudentsListExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, WithStyles
{
    protected $students;

    public function __construct($students)
    {
        $this->students = $students;
    }

    /**
     * Format text with proper capitalization
     */
    private function formatProperCase($text)
    {
        if (empty($text)) return '';
        
        // Handle special cases for course names and common words
        $text = strtolower(trim($text));
        
        // Split by common delimiters
        $words = preg_split('/[\s\-_\/]+/', $text);
        $formattedWords = [];
        
        foreach ($words as $word) {
            if (empty($word)) continue;
            
            // Special handling for common abbreviations and words
            $upperWords = ['IT', 'ICT', 'BSC', 'MSC', 'PHD', 'BA', 'MA', 'HND', 'OND', 'NCE'];
            $lowerWords = ['of', 'and', 'in', 'the', 'for', 'with', 'to', 'at', 'by'];
            
            if (in_array(strtoupper($word), $upperWords)) {
                $formattedWords[] = strtoupper($word);
            } elseif (in_array(strtolower($word), $lowerWords) && count($formattedWords) > 0) {
                $formattedWords[] = strtolower($word);
            } else {
                $formattedWords[] = ucfirst($word);
            }
        }
        
        return implode(' ', $formattedWords);
    }

    /**
     * Format gender to M/F
     */
    private function formatGender($gender)
    {
        if (empty($gender)) return '';
        
        $g = strtolower(trim($gender));
        if ($g === 'male' || $g === 'm') return 'M';
        if ($g === 'female' || $g === 'f') return 'F';
        
        return strtoupper(substr($gender, 0, 1)); // Fallback to first letter uppercase
    }

    public function collection()
    {
        return $this->students;
    }

    public function headings(): array
    {
        return [
            'matric_no',
            'fname', 
            'mname',
            'lname',
            'phone',
            'state',
            'class_of_degree',
            'dob',
            'graduation_year',
            'gender',
            'marital_status',
            'jamb_no',
            'course_study',
            'study_mode'
        ];
    }

    public function map($student): array
    {
        return [
            strtoupper($student->matric_no ?? ''), // CAPITAL LETTERS for matric_no
            $this->formatProperCase($student->fname ?? ''), // Proper case for names
            $this->formatProperCase($student->mname ?? ''), // Proper case for names
            $this->formatProperCase($student->lname ?? ''), // Proper case for names
            $student->phone ?? '', // Phone as text
            $this->formatProperCase($student->state ?? ''), // Proper case for state
            $this->formatProperCase($student->class_of_degree ?? ''), // Proper case for degree
            $student->dob ? $student->dob->format('d/m/Y') : '', // dd/mm/yyyy format (e.g., 15/03/1999)
            (string)($student->graduation_year ?? ''), // Ensure graduation year is treated as text
            $this->formatGender($student->gender ?? ''), // M/F format for gender
            $this->formatProperCase($student->marital_status ?? ''), // Proper case for marital status
            strtoupper($student->jamb_no ?? ''), // CAPITAL LETTERS for jamb_no
            $this->formatProperCase($student->course_study ?? ''), // Proper case for course
            $this->formatProperCase($student->study_mode ?? '') // Proper case for study mode
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT, // matric_no
            'E' => NumberFormat::FORMAT_TEXT, // phone
            'H' => NumberFormat::FORMAT_TEXT, // dob
            'I' => NumberFormat::FORMAT_TEXT, // graduation_year
            'L' => NumberFormat::FORMAT_TEXT, // jamb_no
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => ['font' => ['bold' => true]],
        ];
    }
}