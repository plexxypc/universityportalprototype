@props([
    'name',
    'id' => null,
    'type' => 'text',
    'inputmode' => 'text',
    'value' => null,
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

<input
    id="{{ $field_id }}"
    name="{{ $name }}"
    type="{{ $type }}"
    inputmode="{{ $inputmode }}"
    @if ($value !== null) value="{{ $value }}" @endif
    @if ($required) required aria-required="true" @endif
    @if (filled($error)) aria-invalid="true" @endif
    @if ($described_by !== '') aria-describedby="{{ $described_by }}" @endif
    @disabled($disabled)
    {{ $attributes->merge(['class' => \App\Support\ControlClasses::input()]) }}
/>
