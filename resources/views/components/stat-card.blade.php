@props([
    'label',
    'hint' => null,
])

<article {{ $attributes->merge(['class' => 'max-w-full min-w-0 rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24']) }}>
    <p class="text-small font-normal text-muted">{{ $label }}</p>
    <p class="mt-space-4 text-h1 font-semibold tabular-nums text-text">{{ $slot }}</p>
    @if (filled($hint))
        <p class="mt-space-4 text-small font-normal text-muted">{{ $hint }}</p>
    @endif
</article>
