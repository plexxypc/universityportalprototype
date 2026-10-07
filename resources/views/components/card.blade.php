@props([
    'title' => null,
])

<section {{ $attributes->merge(['class' => 'max-w-full min-w-0 rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24']) }}>
    @if (filled($title) || isset($actions))
        <header class="mb-space-16 flex max-w-full flex-wrap items-center justify-between gap-space-8">
            @if (filled($title))
                <h3 class="text-h3 font-semibold text-text">{{ $title }}</h3>
            @endif
            @isset($actions)
                <div class="flex max-w-full flex-wrap items-center gap-space-8">
                    {{ $actions }}
                </div>
            @endisset
        </header>
    @endif
    {{ $slot }}
</section>
