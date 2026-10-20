@extends('layouts.student')

@section('title', 'Fees')

@section('current', 'fees')

@section('content')
    <x-page-header title="Fees" description="Invoices for your sessions." />

    <x-card title="Outstanding balance">
        <p class="text-h2 font-semibold text-text"><x-money :kobo="$outstanding_kobo" /></p>
    </x-card>

    <div class="mt-space-16">
        @if ($invoices->isEmpty())
            <x-empty-state message="No invoices yet." />
        @else
            <div class="flex flex-col gap-space-16">
                @foreach ($invoices as $invoice)
                    <x-card>
                        <div class="flex max-w-full flex-wrap items-start justify-between gap-space-12">
                            <div class="min-w-0">
                                <p class="text-body font-semibold text-text">{{ $invoice->number }}</p>
                                <p class="mt-space-4 text-small text-muted">{{ $invoice->academicSession?->name }}</p>
                            </div>
                            <x-status-badge :status="$invoice->status" />
                        </div>
                        <p class="mt-space-12 text-body text-text">
                            Outstanding
                            <x-money :kobo="\App\Services\InvoiceService::outstandingKobo($invoice)" />
                        </p>
                        <div class="mt-space-12">
                            <a class="text-body font-semibold text-primary-700 underline" href="{{ route('student.fees.show', $invoice) }}">View</a>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </div>
@endsection
