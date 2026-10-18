@props([
    'current' => 'home',
])

<nav aria-label="Primary" {{ $attributes->merge(['class' => 'fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface md:hidden']) }}>
    <ul class="grid grid-cols-5">
        @foreach (\App\Support\StudentNavigation::items() as $item)
            <li class="min-w-0">
                <x-student.nav-link :item="$item" :current="$current" variant="bar" />
            </li>
        @endforeach
    </ul>
</nav>
