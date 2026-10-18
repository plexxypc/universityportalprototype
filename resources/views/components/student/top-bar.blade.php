@php
    $student_chrome = \App\Support\CurrentStudent::layoutIdentity();
    $home = $student_chrome['home'] ?? route('login');
@endphp

<header {{ $attributes->merge(['class' => 'flex h-14 items-center justify-between gap-space-8 border-b border-border bg-surface px-page-mobile md:hidden']) }}>
    <a href="{{ $home }}" class="flex min-w-0 items-center gap-space-8 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
        <img src="{{ $institution['logo_url'] }}" alt="" width="32" height="32" class="size-8 shrink-0 rounded-control">
        <span class="min-w-0">
            <span class="block truncate text-body font-semibold text-text">{{ $institution['name'] }}</span>
            <span class="block truncate text-small font-normal text-muted">{{ $institution['code'] }}</span>
        </span>
    </a>
    <div class="flex shrink-0 items-center">
        @auth
            <x-student.notification-bell />
        @else
            @isset($bell)
                {{ $bell }}
            @endisset
        @endauth
        @if ($student_chrome !== null)
            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-0 items-center gap-space-8 rounded-control px-space-8 text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open ? 'true' : 'false'"
                    aria-haspopup="menu"
                >
                    <x-ui.icon name="user" class="size-5" />
                    <span class="truncate">{{ $student_chrome['first_name'] }}</span>
                </button>
                <div x-show="open" x-cloak role="menu" class="absolute end-0 z-50 mt-space-8 w-64 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-space-12 shadow-lg">
                    <p class="text-body font-semibold text-text">{{ $student_chrome['full_name'] }}</p>
                    <p class="text-small font-normal text-muted tabular-nums">{{ $student_chrome['matric_no'] }}</p>
                    @if (\Illuminate\Support\Facades\Route::has('student.profile'))
                        <a href="{{ route('student.profile') }}" role="menuitem" class="mt-space-12 flex min-h-11 w-full items-center text-body font-semibold text-text">Profile</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" role="menuitem" class="flex min-h-11 w-full items-center text-body font-semibold text-text">Sign out</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</header>
