@props([
    'name',
    'id' => null,
    'disabled' => false,
    'autocomplete' => 'current-password',
])

@aware(['help' => null, 'error' => null, 'required' => false])

@php
    $field_id = $id ?? \App\Support\FieldId::for($name);
    $described_by = collect([
        filled($help) ? \App\Support\FieldId::help($name) : null,
        filled($error) ? \App\Support\FieldId::error($name) : null,
    ])->filter()->implode(' ');
@endphp

<div x-data="{ shown: false }" class="relative max-w-full min-w-0">
    <input
        id="{{ $field_id }}"
        name="{{ $name }}"
        type="password"
        autocomplete="{{ $autocomplete }}"
        x-bind:type="shown ? 'text' : 'password'"
        @if ($required) required aria-required="true" @endif
        @if (filled($error)) aria-invalid="true" @endif
        @if ($described_by !== '') aria-describedby="{{ $described_by }}" @endif
        @disabled($disabled)
        {{ $attributes->merge(['class' => \App\Support\ControlClasses::input().' pe-12']) }}
    />
    <button
        type="button"
        class="absolute end-0 top-0 inline-flex h-11 w-11 items-center justify-center rounded-control text-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 md:h-10 md:w-10"
        x-on:click="shown = ! shown"
        x-bind:aria-pressed="shown ? 'true' : 'false'"
        x-bind:aria-label="shown ? 'Hide password' : 'Show password'"
        aria-label="Show password"
        @disabled($disabled)
    >
        <x-ui.icon name="eye" x-show="! shown" />
        <x-ui.icon name="eye-off" x-cloak x-show="shown" />
    </button>
</div>
