@props([
    'id',
    'title',
    'consequence',
    'confirmLabel' => 'Confirm',
])

{{-- Escape cancels. The overlay does not confirm or dismiss. x-trap returns focus. --}}
<div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @isset($trigger)
        <div>{{ $trigger }}</div>
    @endisset

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-space-16">
        <div class="absolute inset-0 bg-text/40"></div>
        <div
            x-trap="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $id }}-title"
            aria-describedby="{{ $id }}-consequence"
            class="relative w-full max-w-lg rounded-dialog border border-border bg-surface p-space-16 shadow-lg sm:p-space-24"
        >
            <h2 id="{{ $id }}-title" class="text-h3 font-semibold text-text">{{ $title }}</h2>
            <p id="{{ $id }}-consequence" class="mt-space-12 text-body font-normal text-text">{{ $consequence }}</p>
            <div class="mt-space-24 flex max-w-full flex-wrap justify-end gap-space-8">
                <x-button variant="secondary" type="button" x-on:click="open = false">Cancel</x-button>
                <x-button variant="destructive" type="button" x-on:click="open = false; $dispatch('confirmed', { id: @js($id) })">{{ $confirmLabel }}</x-button>
            </div>
        </div>
    </div>
</div>
