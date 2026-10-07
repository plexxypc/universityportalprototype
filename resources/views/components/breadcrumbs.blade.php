@props([
    'items',
])

<nav aria-label="Breadcrumb" {{ $attributes->merge(['class' => 'mb-space-16 max-w-full']) }}>
    <ol class="flex max-w-full flex-wrap items-center gap-space-8 text-small font-normal">
        @foreach ($items as $item)
            <li class="flex min-w-0 items-center gap-space-8">
                @if (! $loop->last && filled($item['href'] ?? null))
                    <a href="{{ $item['href'] }}" class="text-primary-600 underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">{{ $item['label'] }}</a>
                @else
                    <span class="text-text" @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
                @if (! $loop->last)
                    <span aria-hidden="true" class="text-muted">/</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
