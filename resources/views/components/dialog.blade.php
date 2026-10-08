@props([
    'id',
    'title',
])

{{-- x-trap returns focus when open becomes false. Escape only closes. --}}
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @isset($trigger)
        <div>{{ $trigger }}</div>
    @endisset

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-space-16">
        <div class="absolute inset-0 bg-text/40" x-on:click="open = false"></div>
        <div
            x-trap="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $id }}-title"
            class="relative w-full max-w-lg rounded-dialog border border-border bg-surface p-space-16 shadow-lg sm:p-space-24"
        >
            <div class="flex items-start justify-between gap-space-12">
                <h2 id="{{ $id }}-title" class="text-h3 font-semibold text-text">{{ $title }}</h2>
                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                    x-on:click="open = false"
                >
                    Close
                </button>
            </div>
            <div class="mt-space-12 text-body font-normal text-text">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
