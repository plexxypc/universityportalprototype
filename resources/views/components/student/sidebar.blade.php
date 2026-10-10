@props([
    'current' => 'home',
])

<aside {{ $attributes->merge(['class' => 'hidden h-screen w-60 shrink-0 flex-col border-e border-border bg-surface md:flex']) }}>
    <a href="{{ url('/design-preview/student') }}" class="flex min-h-11 items-center gap-space-12 px-space-16 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
        <img src="{{ asset(config('portal.institution.logo')) }}" alt="" width="32" height="32" class="size-8 shrink-0 rounded-control">
        <span class="truncate text-body font-semibold text-text">{{ config('portal.institution.name') }}</span>
    </a>
    <nav aria-label="Primary" class="flex flex-1 flex-col gap-space-4 px-space-12 py-space-16">
        @foreach (\App\Support\StudentNavigation::items() as $item)
            <a
                href="#{{ $item['id'] }}"
                @if ($current === $item['id']) aria-current="page" @endif
                class="flex min-h-11 items-center gap-space-12 rounded-control px-space-12 text-body font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 {{ $current === $item['id'] ? 'bg-primary-50 text-primary-600' : 'text-text hover:bg-primary-50' }}"
            >
                <x-ui.icon :name="$item['icon']" class="size-5" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
    <form method="POST" action="{{ route('logout') }}" class="px-space-12 pb-space-16">
        @csrf
        <button type="submit" class="flex min-h-11 w-full items-center rounded-control px-space-12 text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
            Sign out
        </button>
    </form>
</aside>
