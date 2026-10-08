@props([
    'message',
])

<div {{ $attributes->merge(['class' => 'pointer-events-auto flex w-full min-w-0 items-start justify-between gap-space-12 rounded-card border border-border bg-surface p-space-16 shadow-lg']) }} role="status">
    <p class="text-body font-normal text-text">{{ $message }}</p>
    <button type="button" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
        Dismiss
    </button>
</div>
