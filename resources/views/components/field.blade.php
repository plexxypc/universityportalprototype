@props([
    'name',
    'label',
    'required' => false,
    'help' => null,
    'error' => null,
])

@php
    $field_id = \App\Support\FieldId::for($name);
    $help_id = filled($help) ? \App\Support\FieldId::help($name) : null;
    $error_id = filled($error) ? \App\Support\FieldId::error($name) : null;
@endphp

<div {{ $attributes->merge(['class' => 'max-w-full min-w-0']) }}>
    <label for="{{ $field_id }}" class="block text-body font-semibold text-text">
        {{ $label }}
        @if ($required)
            <span class="text-danger-text" aria-hidden="true">*</span>
            <span class="sr-only">required</span>
        @endif
    </label>

    <div class="mt-space-8">
        {{ $slot }}
    </div>

    @if ($help_id)
        <p id="{{ $help_id }}" class="mt-space-8 text-small font-normal text-muted">{{ $help }}</p>
    @endif

    @if ($error_id)
        <p id="{{ $error_id }}" class="mt-space-8 flex items-start gap-space-8 text-small font-normal text-danger-text">
            <x-ui.icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
