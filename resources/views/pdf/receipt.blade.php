<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Receipt {{ $number }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
            h1 { font-size: 16px; }
        </style>
    </head>
    <body>
        <p>{{ $institution_name }}</p>
        <h1>Receipt {{ $number }}</h1>
        <p>{{ $status }}</p>
        <p>{{ $amount }}</p>
        <p>{{ $student_name }}</p>
        <p>{{ $matric_no }}</p>
    </body>
</html>
