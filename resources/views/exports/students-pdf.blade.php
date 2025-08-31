<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>NYSC Students Data Export</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #4472C4;
            padding-bottom: 15px;
        }
        .header h1 {
            color: #4472C4;
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .summary {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary-item {
            display: inline-block;
            margin-right: 30px;
            margin-bottom: 10px;
        }
        .summary-label {
            font-weight: bold;
            color: #4472C4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 10px;
        }
        th {
            background-color: #4472C4;
            color: white;
            padding: 8px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #ddd;
        }
        td {
            padding: 6px 4px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .status-paid {
            color: #28a745;
            font-weight: bold;
        }
        .status-unpaid {
            color: #dc3545;
            font-weight: bold;
        }
        .status-submitted {
            color: #28a745;
        }
        .status-not-submitted {
            color: #ffc107;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>NYSC Students Data Export</h1>
        <p>Veritas University Abuja</p>
        <p>Generated on: {{ now()->format('F j, Y \\a\\t g:i A') }}</p>
    </div>

    <div class="summary">
        <div class="summary-item">
            <span class="summary-label">Total Students:</span> {{ $students->count() }}
        </div>
        <div class="summary-item">
            <span class="summary-label">Paid:</span> {{ $students->where('is_paid', true)->count() }}
        </div>
        <div class="summary-item">
            <span class="summary-label">Unpaid:</span> {{ $students->where('is_paid', false)->count() }}
        </div>
        <div class="summary-item">
            <span class="summary-label">Submitted:</span> {{ $students->where('is_submitted', true)->count() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>S/N</th>
                <th>Matric No</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Department</th>
                <th>Level</th>
                <th>CGPA</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $index => $student)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $student->matric_no }}</td>
                    <td>{{ trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')) }}</td>
                    <td>{{ $student->email }}</td>
                    <td>{{ $student->phone }}</td>
                    <td>{{ $student->department }}</td>
                    <td>{{ $student->level }}</td>
                    <td>{{ $student->cgpa }}</td>
                    <td class="{{ $student->is_paid ? 'status-paid' : 'status-unpaid' }}">
                        {{ $student->is_paid ? 'Paid' : 'Unpaid' }}
                        @if($student->payments->first())
                            <br><small>₦{{ number_format($student->payments->first()->amount, 2) }}</small>
                        @endif
                    </td>
                    <td class="{{ $student->is_submitted ? 'status-submitted' : 'status-not-submitted' }}">
                        {{ $student->is_submitted ? 'Submitted' : 'Not Submitted' }}
                    </td>
                    <td>{{ $student->created_at ? $student->created_at->format('M j, Y') : '' }}</td>
                </tr>
                @if(($index + 1) % 25 == 0 && $index + 1 < $students->count())
                    </tbody>
                    </table>
                    <div class="page-break"></div>
                    <table>
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>Matric No</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Department</th>
                                <th>Level</th>
                                <th>CGPA</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                @endif
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>This document contains confidential information. Handle with care.</p>
        <p>© {{ date('Y') }} Veritas University Abuja - NYSC Registration System</p>
    </div>
</body>
</html>