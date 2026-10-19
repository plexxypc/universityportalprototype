<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Course registration slip</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
            h1 { font-size: 16px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { text-align: left; padding: 4px; }
        </style>
    </head>
    <body>
        <p>{{ $institution_name }}</p>
        <h1>Course registration slip</h1>
        <p>{{ $student_name }} · {{ $matric_no }}</p>
        <p>{{ $programme_name }}</p>
        <p>{{ $session_name }} · {{ $semester_name }}</p>
        <p>Approved</p>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Units</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($courses as $course)
                    <tr>
                        <td>{{ $course['code'] }}</td>
                        <td>{{ $course['title'] }}</td>
                        <td>{{ $course['credit_units'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p>Total units: {{ $total_units }}</p>
    </body>
</html>
