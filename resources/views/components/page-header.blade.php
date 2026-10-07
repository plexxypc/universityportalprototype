@props([
    'title',
    'description' => null,
])

<header {{ $attributes->merge(['class' => 'mb-space-24 flex max-w-full flex-wrap items-start justify-between gap-space-12']) }}>
    <div class="min-w-0">
        <h1 class="text-h1 font-semibold text-text">{{ $title }}</h1>
        @if (filled($description))
            <p class="mt-space-8 max-w-full text-body font-normal text-muted">{{ $description }}</p>
        @endif
    </div>
    @isset($action)
        <div class="max-w-full shrink-0">
            {{ $action }}
        </div>
    @endisset
</header>
