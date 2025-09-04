<!DOCTYPE html>
<html>
<head>
    <title>Student Data Export</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #000;
            padding: 6px;
        }
    </style>
</head>
<body>
    <h2>NYSC Student Data</h2>
    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Gender</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Matric No</th>
                <th>Dept</th>
                <th>Course</th>
                <th>CGPA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $student)
            <tr>
                <td>{{ $student->student_id }}</td>
                <td>{{ $student->lname }}, {{ $student->fname }} {{ $student->mname }}</td>
                <td>{{ $student->gender }}</td>
                <td>{{ $student->phone }}</td>
                <td>{{ $student->email }}</td>
                <td>{{ $student->matric_no }}</td>
                <td>{{ $student->department }}</td>
                <td>{{ $student->course_study }}</td>
                <td>{{ $student->cgpa }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
