@props([
    'name',
    'label',
    'id' => null,
    'checked' => false,
    'disabled' => false,
    'required' => false,
])

@php
    $field_id = $id ?? \App\Support\FieldId::for($name);
@endphp

<label for="{{ $field_id }}" class="inline-flex min-h-11 max-w-full min-w-0 items-center gap-space-12 text-body font-normal text-text">
    <input
        id="{{ $field_id }}"
        name="{{ $name }}"
        type="checkbox"
        @if ($required) required aria-required="true" @endif
        @checked($checked)
        @disabled($disabled)
        {{ $attributes->merge(['class' => 'size-5 shrink-0 rounded-control border-border text-primary-600 focus:ring-2 focus:ring-primary focus:ring-offset-2']) }}
    />
    <span>{{ $label }}</span>
</label>
