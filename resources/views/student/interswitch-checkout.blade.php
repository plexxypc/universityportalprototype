@extends('layouts.student')

@section('title', 'Pay fees')

@section('current', 'fees')

@section('content')
    <x-page-header title="Pay fees" :description="$payment->reference" />

    <x-card>
        <p class="text-body text-text">
            Amount
            <x-money :kobo="(int) $payment->amount_kobo" />
        </p>
        <form id="interswitch-checkout" class="mt-space-16" method="post" action="{{ $action }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
            @endforeach
            <x-button type="submit">Make Payment</x-button>
        </form>
    </x-card>

    <script>
        document.getElementById('interswitch-checkout').submit();
    </script>
@endsection
