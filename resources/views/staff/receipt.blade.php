<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Receipt {{ $number }}</title>
    </head>
    <body>
        <p>{{ $institution_name }}</p>
        <h1>Receipt {{ $number }}</h1>
        <p>{{ $status }}</p>
        <p>{{ $amount }}</p>
        <p>{{ $student_name }}</p>
        <p>{{ $matric_no }}</p>
        <p><a href="{{ $pdf_url }}">Download PDF</a></p>
    </body>
</html>
