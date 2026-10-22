@extends('layouts.student')

@section('title', 'Receipt')

@section('current', 'fees')

@section('content')
    <x-page-header title="Receipt" :description="$number" />

    <x-card>
        <p class="text-body font-semibold text-text">{{ $status }}</p>
        <p class="mt-space-12 text-body text-text">{{ $amount }}</p>
        <p class="mt-space-12 text-body text-text">{{ $student_name }}</p>
        <p class="mt-space-12 text-body text-text">{{ $matric_no }}</p>
        <p class="mt-space-16">
            <a class="text-body font-semibold text-primary-700 underline" href="{{ $pdf_url }}">Download PDF</a>
        </p>
    </x-card>
@endsection
