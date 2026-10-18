@props([
    'item',
    'current' => 'home',
    'variant' => 'bar',
])

@php
    $enabled = $item['enabled'] === true && is_string($item['route']);
    $is_current = $current === $item['id'];
    $bar = $variant === 'bar';
    $classes = $bar
        ? 'flex min-h-11 min-w-0 flex-col items-center justify-center gap-space-4 px-space-4 py-space-8 text-small font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2'
        : 'flex min-h-11 min-w-0 items-center gap-space-12 rounded-control px-space-12 text-body font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2';
    $tone = $is_current
        ? ($bar ? 'text-primary-600' : 'bg-primary-50 text-primary-600')
        : ($bar ? 'text-muted' : 'text-text hover:bg-primary-50');
@endphp

@if ($enabled)
    <a
        href="{{ route($item['route']) }}"
        @if ($is_current) aria-current="page" @endif
        {{ $attributes->merge(['class' => $classes.' '.$tone]) }}
    >
        <x-ui.icon :name="$item['icon']" class="size-5" />
        <span class="max-w-full truncate">{{ $item['label'] }}</span>
    </a>
@else
    <span
        aria-disabled="true"
        @if ($is_current) aria-current="page" @endif
        {{ $attributes->merge(['class' => $classes.' text-muted']) }}
    >
        <x-ui.icon :name="$item['icon']" class="size-5" />
        <span class="flex max-w-full flex-col items-center">
            <span class="max-w-full truncate">{{ $item['label'] }}</span>
            <span>Soon</span>
        </span>
    </span>
@endif
