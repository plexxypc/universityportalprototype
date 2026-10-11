<header {{ $attributes->merge(['class' => 'flex h-14 items-center justify-between gap-space-8 border-b border-border bg-surface px-page-mobile md:hidden']) }}>
    <a href="{{ url('/design-preview/student') }}" class="flex min-w-0 items-center gap-space-8 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
        <img src="{{ asset(config('portal.institution.logo')) }}" alt="" width="32" height="32" class="size-8 shrink-0 rounded-control">
        <span class="truncate text-body font-semibold text-text">{{ config('portal.institution.name') }}</span>
    </a>
    <div class="flex shrink-0 items-center">
        @auth
            <x-student.notification-bell />
        @else
            @isset($bell)
                {{ $bell }}
            @endisset
        @endauth
        <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
            <button
                type="button"
                class="inline-flex min-h-11 items-center gap-space-8 rounded-control px-space-8 text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="menu"
            >
                <x-ui.icon name="user" class="size-5" />
                <span>Ada</span>
            </button>
            <div x-show="open" x-cloak role="menu" class="absolute end-0 z-50 mt-space-8 w-64 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-space-12 shadow-lg">
                <p class="text-body font-semibold text-text">Ada Okonkwo</p>
                <p class="text-small font-normal text-muted tabular-nums">CSC/2026/001</p>
                <button type="button" role="menuitem" class="mt-space-12 flex min-h-11 w-full items-center text-body font-semibold text-text">Profile</button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" role="menuitem" class="flex min-h-11 w-full items-center text-body font-semibold text-text">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
