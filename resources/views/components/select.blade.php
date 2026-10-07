@props([
    'name',
    'id' => null,
    'disabled' => false,
])

@aware(['help' => null, 'error' => null, 'required' => false])

@php
    $field_id = $id ?? \App\Support\FieldId::for($name);
    $described_by = collect([
        filled($help) ? \App\Support\FieldId::help($name) : null,
        filled($error) ? \App\Support\FieldId::error($name) : null,
    ])->filter()->implode(' ');
@endphp

<div class="relative max-w-full min-w-0">
    <select
        id="{{ $field_id }}"
        name="{{ $name }}"
        @if ($required) required aria-required="true" @endif
        @if (filled($error)) aria-invalid="true" @endif
        @if ($described_by !== '') aria-describedby="{{ $described_by }}" @endif
        @disabled($disabled)
        {{ $attributes->merge(['class' => \App\Support\ControlClasses::input().' appearance-none pe-10']) }}
    >
        {{ $slot }}
    </select>
    <span class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-space-12 text-muted">
        <x-ui.icon name="chevron-down" />
    </span>
</div>
