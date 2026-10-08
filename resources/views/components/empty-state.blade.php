@props([
    'message',
])

<div {{ $attributes->merge(['class' => 'flex max-w-full flex-col items-center px-space-16 py-space-32 text-center']) }}>
    <span class="inline-flex size-12 items-center justify-center rounded-badge bg-neutral-bg text-muted">
        <x-ui.icon name="inbox" class="size-5" />
    </span>
    <p class="mt-space-16 text-body font-normal text-text">{{ $message }}</p>
    @isset($action)
        <div class="mt-space-16">
            {{ $action }}
        </div>
    @endisset
</div>
