@props([
    'current' => 'home',
])

<nav aria-label="Primary" {{ $attributes->merge(['class' => 'fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface md:hidden']) }}>
    <ul class="grid grid-cols-5">
        @foreach (\App\Support\StudentNavigation::items() as $item)
            <li>
                <a
                    href="#{{ $item['id'] }}"
                    @if ($current === $item['id']) aria-current="page" @endif
                    class="flex min-h-11 flex-col items-center justify-center gap-space-4 px-space-4 py-space-8 text-small font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 {{ $current === $item['id'] ? 'text-primary-600' : 'text-muted' }}"
                >
                    <x-ui.icon :name="$item['icon']" class="size-5" />
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
