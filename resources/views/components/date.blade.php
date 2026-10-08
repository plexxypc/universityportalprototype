@props([
    'value' => null,
    'mode' => 'date',
    'sourceTimezone' => null,
])

<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{ $mode === 'datetime' ? \App\Support\Dates::dateTime($value, $sourceTimezone) : \App\Support\Dates::date($value, $sourceTimezone) }}</span>
