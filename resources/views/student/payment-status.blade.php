@extends('layouts.student')

@section('title', $payment->reference)

@section('current', 'fees')

@section('content')
    <x-page-header :title="$payment->reference" description="Payment status">
        <x-slot:action>
            <x-status-badge :status="$payment->status" />
        </x-slot:action>
    </x-page-header>

    <x-card>
        @if ($under_review)
            <p class="text-body text-text">{{ $review_message }}</p>
            <p class="mt-space-12 text-body text-text">{{ $refunds_message }}</p>
        @else
            <p class="text-body text-text">{{ $summary }}</p>
            @if ($payment->receipt !== null)
                <p class="mt-space-12 text-body text-text">Receipt {{ $payment->receipt->number }}</p>
            @endif
        @endif
        <p class="mt-space-12 text-body text-text">
            Amount
            <x-money :kobo="(int) $payment->amount_kobo" />
        </p>
        @if ($errors->any())
            <p class="mt-space-12 text-body text-danger-text" role="alert">{{ $errors->first() }}</p>
        @endif
    </x-card>

    @if ($payment->status->value === 'Pending' || $payment->status->value === 'Expired')
        <form method="POST" action="{{ route('student.payments.check', ['reference' => $payment->reference]) }}" class="mt-space-16">
            @csrf
            <x-button type="submit">Check status</x-button>
        </form>
    @endif
@endsection
