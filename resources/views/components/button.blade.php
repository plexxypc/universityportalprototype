@props([
    'variant' => 'primary',
    'size' => 'default',
    'loading' => false,
    'loadingLabel' => 'Working…',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $is_disabled = $disabled || $loading;
    $classes = \App\Support\ControlClasses::button($variant, $size);

    if ($href && $is_disabled) {
        $classes .= ' pointer-events-none';
    }
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        @if ($is_disabled) aria-disabled="true" tabindex="-1" @endif
        @if ($loading) aria-busy="true" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <x-ui.icon name="spinner" class="size-4 animate-spin motion-reduce:animate-none" />
            <span>{{ $loadingLabel }}</span>
        @else
            {{ $slot }}
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($is_disabled)
        @if ($loading) aria-busy="true" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <x-ui.icon name="spinner" class="size-4 animate-spin motion-reduce:animate-none" />
            <span>{{ $loadingLabel }}</span>
        @else
            {{ $slot }}
        @endif
    </button>
@endif
