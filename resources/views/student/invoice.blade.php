@extends('layouts.student')

@section('title', $invoice->number)

@section('current', 'fees')

@section('content')
    <x-page-header :title="$invoice->number" :description="$invoice->academicSession?->name">
        <x-slot:action>
            <x-status-badge :status="$invoice->status" />
        </x-slot:action>
    </x-page-header>

    <x-card title="Outstanding balance">
        <p class="text-h2 font-semibold text-text"><x-money :kobo="$outstanding_kobo" /></p>
    </x-card>

    <div class="mt-space-16">
        <x-card title="Lines">
            @if ($invoice->items->isEmpty())
                <x-empty-state message="This invoice has no lines." />
            @else
                <ul class="flex flex-col gap-space-12">
                    @foreach ($invoice->items as $item)
                        <li class="flex max-w-full flex-wrap items-center justify-between gap-space-8">
                            <span class="text-body text-text">{{ $item->description }}</span>
                            <x-money :kobo="(int) $item->amount_kobo" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <div class="mt-space-16">
        <x-card title="Adjustments">
            @if ($invoice->adjustments->isEmpty())
                <x-empty-state message="No adjustments." />
            @else
                <ul class="flex flex-col gap-space-12">
                    @foreach ($invoice->adjustments as $adjustment)
                        <li class="flex max-w-full flex-wrap items-center justify-between gap-space-8">
                            <span class="text-body text-text">{{ $adjustment->type->value }} · {{ $adjustment->reason }}</span>
                            <x-money :kobo="(int) $adjustment->amount_kobo" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <div class="mt-space-16">
        <x-card title="Payment history">
            @if ($invoice->payments->isEmpty())
                <x-empty-state message="No payments yet." />
            @else
                <ul class="flex flex-col gap-space-12">
                    @foreach ($invoice->payments as $payment)
                        <li class="flex max-w-full flex-wrap items-center justify-between gap-space-8">
                            <a class="min-w-0 text-body font-semibold text-primary-700 underline" href="{{ route('student.payments.show', ['reference' => $payment->reference]) }}">{{ $payment->reference }}</a>
                            <span class="flex flex-wrap items-center gap-space-8">
                                <x-money :kobo="(int) $payment->amount_kobo" />
                                <x-status-badge :status="$payment->status" />
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    <div class="mt-space-16">
        <x-card title="Pay">
            <form method="POST" action="{{ route('student.fees.pay', $invoice) }}" class="flex max-w-full flex-col gap-space-16">
                @csrf
                <x-field name="amount" label="Amount" :error="$errors->first('amount') ?: $errors->first('payment')">
                    <x-input name="amount" type="text" inputmode="decimal" />
                </x-field>
                <div>
                    <x-button type="submit">Pay</x-button>
                </div>
            </form>
        </x-card>
    </div>
@endsection
