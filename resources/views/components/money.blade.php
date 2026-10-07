@props(['kobo'])

<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ \App\Support\Money::format($kobo) }}</span>
