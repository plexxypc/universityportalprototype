@props([
    'variant' => 'card',
])

@php
    if (! in_array($variant, ['page', 'card', 'row'], true)) {
        throw new InvalidArgumentException('Unknown skeleton variant.');
    }
@endphp

<div role="status" {{ $attributes->merge(['class' => 'max-w-full min-w-0']) }}>
    <p class="sr-only">Loading</p>
    <div aria-hidden="true" class="animate-pulse motion-reduce:animate-none">
        @if ($variant === 'page')
            <div class="h-8 w-48 max-w-full rounded-control bg-neutral-bg"></div>
            <div class="mt-space-16 h-4 w-72 max-w-full rounded-control bg-neutral-bg"></div>
            <div class="mt-space-24 grid gap-space-16 md:grid-cols-3">
                <div class="h-24 rounded-card bg-neutral-bg"></div>
                <div class="h-24 rounded-card bg-neutral-bg"></div>
                <div class="h-24 rounded-card bg-neutral-bg"></div>
            </div>
        @elseif ($variant === 'row')
            <div class="flex h-12 items-center gap-space-12">
                <div class="h-4 w-1/4 rounded-control bg-neutral-bg"></div>
                <div class="h-4 w-1/4 rounded-control bg-neutral-bg"></div>
                <div class="h-4 w-1/6 rounded-control bg-neutral-bg"></div>
                <div class="ms-auto h-4 w-16 rounded-control bg-neutral-bg"></div>
            </div>
        @else
            <div class="rounded-card border border-border bg-surface p-space-16">
                <div class="h-4 w-32 max-w-full rounded-control bg-neutral-bg"></div>
                <div class="mt-space-12 h-4 w-full rounded-control bg-neutral-bg"></div>
                <div class="mt-space-8 h-4 w-2/3 rounded-control bg-neutral-bg"></div>
            </div>
        @endif
    </div>
</div>
