@props([
    'current' => 'home',
])

@php
    $student_chrome = \App\Support\CurrentStudent::layoutIdentity();
    $home = $student_chrome['home'] ?? route('login');
@endphp

<aside {{ $attributes->merge(['class' => 'hidden h-screen w-60 shrink-0 flex-col border-e border-border bg-surface md:flex']) }}>
    <div class="flex items-center justify-between gap-space-8 pe-space-8">
        <a href="{{ $home }}" class="flex min-h-11 min-w-0 flex-1 items-center gap-space-12 px-space-16 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
            <img src="{{ $institution['logo_url'] }}" alt="" width="32" height="32" class="size-8 shrink-0 rounded-control">
            <span class="min-w-0">
                <span class="block truncate text-body font-semibold text-text">{{ $institution['name'] }}</span>
                <span class="block truncate text-small font-normal text-muted">{{ $institution['code'] }}</span>
            </span>
        </a>
        @auth
            <x-student.notification-bell />
        @endauth
    </div>
    <nav aria-label="Primary" class="flex flex-1 flex-col gap-space-4 px-space-12 py-space-16">
        @foreach (\App\Support\StudentNavigation::items() as $item)
            <x-student.nav-link :item="$item" :current="$current" variant="sidebar" />
        @endforeach
    </nav>
    @if ($student_chrome !== null)
        <div class="px-space-16 pb-space-8">
            <p class="truncate text-body font-semibold text-text">{{ $student_chrome['full_name'] }}</p>
            <p class="truncate text-small font-normal text-muted tabular-nums">{{ $student_chrome['matric_no'] }}</p>
        </div>
    @endif
    <form method="POST" action="{{ route('logout') }}" class="px-space-12 pb-space-16">
        @csrf
        <button type="submit" class="flex min-h-11 w-full items-center rounded-control px-space-12 text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
            Sign out
        </button>
    </form>
</aside>
