@extends('layouts.student')

@section('title', 'Demo payment')

@section('current', 'fees')

@section('content')
    <x-page-header title="Demo payment" :description="$payment->reference" />

    <x-card>
        <p class="text-body font-semibold text-text">{{ $banner }}</p>
        <p class="mt-space-12 text-body text-text">
            Amount
            <x-money :kobo="(int) $payment->amount_kobo" />
        </p>
    </x-card>

    <div class="mt-space-16 flex max-w-full flex-col gap-space-12">
        @foreach ($signatures as $outcome => $signature)
            <form method="POST" action="{{ route('student.payments.demo.store', ['reference' => $payment->reference]) }}">
                @csrf
                <input type="hidden" name="outcome" value="{{ $outcome }}" />
                <input type="hidden" name="signature" value="{{ $signature }}" />
                <x-button type="submit">{{ ucfirst($outcome) }}</x-button>
            </form>
        @endforeach
    </div>

    @if ($errors->any())
        <p class="mt-space-16 text-body text-danger-text" role="alert">{{ $errors->first() }}</p>
    @endif
@endsection
