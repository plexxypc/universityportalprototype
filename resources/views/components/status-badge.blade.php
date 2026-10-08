@props(['status'])

@php
    $tone = \App\Support\StatusTone::for($status);
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex max-w-full items-center gap-space-4 rounded-badge px-space-12 py-space-4 text-small font-semibold '. \App\Support\StatusTone::classes($tone)]) }}>
    <x-ui.icon :name="\App\Support\StatusTone::icon($tone)" />
    <span>{{ $status->value }}</span>
</span>
