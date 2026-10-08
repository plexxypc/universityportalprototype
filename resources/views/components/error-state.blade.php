@props([
    'message',
    'hint',
    'retryLabel' => 'Try again',
])

<div role="alert" {{ $attributes->merge(['class' => 'max-w-full rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24']) }}>
    <div class="flex items-start gap-space-12">
        <x-ui.icon name="alert" class="mt-0.5 size-5 text-danger-text" />
        <div class="min-w-0">
            <p class="text-body font-semibold text-text">{{ $message }}</p>
            <p class="mt-space-8 text-body font-normal text-muted">{{ $hint }}</p>
            <div class="mt-space-16">
                <x-button type="button" x-on:click="$dispatch('retry')">{{ $retryLabel }}</x-button>
            </div>
        </div>
    </div>
</div>
