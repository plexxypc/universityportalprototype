@props([
    'name',
    'id' => null,
    'disabled' => false,
    'rows' => 4,
])

@aware(['help' => null, 'error' => null, 'required' => false])

@php
    $field_id = $id ?? \App\Support\FieldId::for($name);
    $described_by = collect([
        filled($help) ? \App\Support\FieldId::help($name) : null,
        filled($error) ? \App\Support\FieldId::error($name) : null,
    ])->filter()->implode(' ');
@endphp

<textarea
    id="{{ $field_id }}"
    name="{{ $name }}"
    rows="{{ $rows }}"
    @if ($required) required aria-required="true" @endif
    @if (filled($error)) aria-invalid="true" @endif
    @if ($described_by !== '') aria-describedby="{{ $described_by }}" @endif
    @disabled($disabled)
    {{ $attributes->merge(['class' => \App\Support\ControlClasses::textarea()]) }}
>{{ $slot }}</textarea>
