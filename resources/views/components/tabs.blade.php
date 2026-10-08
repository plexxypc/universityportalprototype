@props([
    'tabs',
    'label' => 'Sections',
    'selected' => null,
])

@php
    $ids = array_keys($tabs);
    $current = $selected ?? ($ids[0] ?? null);
@endphp

<div
    x-data='{
        tabs: @js($ids),
        selected: @js($current),
        move(step) {
            const index = this.tabs.indexOf(this.selected);
            const next = this.tabs[(index + step + this.tabs.length) % this.tabs.length];
            this.selected = next;
            this.$refs[next].focus();
        }
    }'
>
    <div
        role="tablist"
        aria-label="{{ $label }}"
        class="flex max-w-full gap-space-8 overflow-x-auto"
        x-on:keydown.right.prevent="move(1)"
        x-on:keydown.left.prevent="move(-1)"
    >
        @foreach ($tabs as $id => $tab_label)
            <button
                type="button"
                role="tab"
                id="tab-{{ $id }}"
                x-ref="{{ $id }}"
                aria-controls="panel-{{ $id }}"
                aria-selected="{{ $id === $current ? 'true' : 'false' }}"
                tabindex="{{ $id === $current ? '0' : '-1' }}"
                x-bind:aria-selected="selected === @js($id) ? 'true' : 'false'"
                x-bind:tabindex="selected === @js($id) ? '0' : '-1'"
                x-on:click="selected = @js($id)"
                class="inline-flex min-h-11 shrink-0 items-center rounded-control px-space-16 text-body font-semibold text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                x-bind:class="selected === @js($id) ? 'bg-primary-50' : ''"
            >
                {{ $tab_label }}
            </button>
        @endforeach
    </div>
    <div class="mt-space-16">
        {{ $slot }}
    </div>
</div>
