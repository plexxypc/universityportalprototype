@extends('layouts.student')

@section('title', 'Fees')

@section('current', 'fees')

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Home', 'href' => '#home'],
        ['label' => 'Fees'],
    ]" />
    <x-page-header title="Fees" description="Invoices for the current session.">
        <x-slot:action>
            <x-button>Pay now</x-button>
        </x-slot:action>
    </x-page-header>
    <x-stat-card label="Outstanding fees" hint="Current session">
        <x-money :kobo="12500000" />
    </x-stat-card>
    <p class="mt-space-16 max-w-full text-body font-normal text-text">
        This is a sample detail page. The menu, bell and account use demo content.
    </p>
@endsection
