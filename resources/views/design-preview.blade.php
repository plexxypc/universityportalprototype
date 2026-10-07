@extends('layouts.app')

@section('title', 'Design preview')

@section('content')
    <header class="mb-space-32 max-w-full">
        <p class="text-small font-normal text-muted">Local only</p>
        <h1 class="text-display font-bold">Design preview</h1>
        <p class="mt-space-8 max-w-full text-body font-normal text-muted">
            Colour, type, radius and plain controls from the design tokens.
        </p>
    </header>

    <section class="mb-space-48 max-w-full" aria-labelledby="colours-heading">
        <h2 id="colours-heading" class="mb-space-16 text-h2 font-semibold">Colours</h2>
        <div class="grid max-w-full grid-cols-1 gap-space-12 sm:grid-cols-2">
            @foreach ([
                ['name' => 'Primary', 'hex' => '#6366F1', 'chip' => 'bg-primary', 'note' => 'Brand accent, focus ring, icons. Not a fill for white text.'],
                ['name' => 'Primary 600', 'hex' => '#4F46E5', 'chip' => 'bg-primary-600', 'note' => 'Action fill. White text meets contrast.'],
                ['name' => 'Primary 700', 'hex' => '#4338CA', 'chip' => 'bg-primary-700', 'note' => 'Button hover and pressed.'],
                ['name' => 'Primary 50', 'hex' => '#EEF2FF', 'chip' => 'bg-primary-50', 'note' => 'Selected rows and soft highlights.'],
                ['name' => 'Background', 'hex' => '#F8FAFC', 'chip' => 'bg-background', 'note' => 'Page background.'],
                ['name' => 'Surface', 'hex' => '#FFFFFF', 'chip' => 'bg-surface', 'note' => 'Cards, dialogs and tables.'],
                ['name' => 'Text', 'hex' => '#0F172A', 'chip' => 'bg-text', 'note' => 'Main text.'],
                ['name' => 'Muted', 'hex' => '#64748B', 'chip' => 'bg-muted', 'note' => 'Secondary text and placeholders.'],
                ['name' => 'Border', 'hex' => '#E2E8F0', 'chip' => 'bg-border', 'note' => 'Dividers, cards and inputs.'],
                ['name' => 'Destructive', 'hex' => '#DC2626', 'chip' => 'bg-destructive', 'note' => 'Delete, deactivate, reject.'],
                ['name' => 'Destructive hover', 'hex' => '#B91C1C', 'chip' => 'bg-destructive-hover', 'note' => 'Destructive pressed state.'],
            ] as $swatch)
                <article class="min-w-0 overflow-hidden rounded-card border border-border bg-surface shadow-sm">
                    <div class="{{ $swatch['chip'] }} h-12 border-b border-border"></div>
                    <div class="p-space-16">
                        <h3 class="text-h3 font-semibold">{{ $swatch['name'] }}</h3>
                        <p class="font-mono text-mono font-medium text-muted">{{ $swatch['hex'] }}</p>
                        <p class="mt-space-4 text-small font-normal text-muted">{{ $swatch['note'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="mb-space-48 max-w-full" aria-labelledby="status-heading">
        <h2 id="status-heading" class="mb-space-16 text-h2 font-semibold">Status colours</h2>
        <ul class="flex max-w-full flex-col gap-space-8">
            @foreach ([
                ['label' => 'Success', 'text' => 'text-success-text', 'bg' => 'bg-success-bg', 'pair' => '#166534 on #DCFCE7'],
                ['label' => 'Warning', 'text' => 'text-warning-text', 'bg' => 'bg-warning-bg', 'pair' => '#92400E on #FEF3C7'],
                ['label' => 'Danger', 'text' => 'text-danger-text', 'bg' => 'bg-danger-bg', 'pair' => '#991B1B on #FEE2E2'],
                ['label' => 'Info', 'text' => 'text-info-text', 'bg' => 'bg-info-bg', 'pair' => '#3730A3 on #E0E7FF'],
                ['label' => 'Neutral', 'text' => 'text-neutral-text', 'bg' => 'bg-neutral-bg', 'pair' => '#334155 on #F1F5F9'],
            ] as $status)
                <li class="flex min-w-0 flex-wrap items-center justify-between gap-space-8 rounded-card border border-border bg-surface px-space-16 py-space-12">
                    <span class="{{ $status['bg'] }} {{ $status['text'] }} inline-flex rounded-badge px-space-12 py-space-4 text-small font-semibold">
                        {{ $status['label'] }}
                    </span>
                    <span class="min-w-0 font-mono text-mono font-medium break-all text-muted">{{ $status['pair'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mb-space-48 max-w-full" aria-labelledby="type-heading">
        <h2 id="type-heading" class="mb-space-16 text-h2 font-semibold">Type scale</h2>
        <div class="max-w-full rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24">
            <p class="text-display font-bold">Display 30/38</p>
            <p class="mt-space-16 text-h1 font-semibold">Heading 1 24/32</p>
            <p class="mt-space-12 text-h2 font-semibold">Heading 2 20/28</p>
            <p class="mt-space-12 text-h3 font-semibold">Heading 3 16/24</p>
            <p class="mt-space-12 text-body font-normal">Body 14/22. Default reading text for the portal.</p>
            <p class="mt-space-8 text-small font-normal text-muted">Small 12/18. Captions and helper text.</p>
            <p class="mt-space-8 font-mono text-mono font-medium tabular-nums">Mono 13/20 · ₦125,000.00</p>
            <p class="mt-space-8 text-input font-normal">Input 16/24. Mobile fields stay at 16px.</p>
        </div>
    </section>

    <section class="mb-space-48 max-w-full" aria-labelledby="controls-heading">
        <h2 id="controls-heading" class="mb-space-16 text-h2 font-semibold">Buttons and field</h2>
        <div class="max-w-full rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24">
            <div class="flex max-w-full flex-wrap items-center gap-space-8">
                <button type="button" class="inline-flex h-10 items-center rounded-control bg-primary-600 px-space-16 text-body font-semibold text-white hover:bg-primary-700">
                    Primary
                </button>
                <button type="button" class="inline-flex h-10 items-center rounded-control border border-border bg-surface px-space-16 text-body font-semibold text-text">
                    Secondary
                </button>
                <button type="button" class="inline-flex h-10 items-center rounded-control px-space-16 text-body font-semibold text-text hover:bg-primary-50">
                    Ghost
                </button>
                <button type="button" class="inline-flex h-10 items-center rounded-control bg-destructive px-space-16 text-body font-semibold text-white hover:bg-destructive-hover">
                    Destructive
                </button>
                <button type="button" class="inline-flex h-10 items-center rounded-control px-space-8 text-body font-semibold text-primary-600 underline-offset-2 hover:underline">
                    Link
                </button>
            </div>
            <label class="mt-space-24 block max-w-full text-body font-semibold" for="preview-name">
                Sample field
            </label>
            <input
                id="preview-name"
                class="mt-space-8 h-10 w-full max-w-full rounded-control border border-border bg-surface px-space-12 text-input font-normal text-text outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                type="text"
                name="preview_name"
                inputmode="text"
            >
            <p class="mt-space-8 text-small font-normal text-muted">Buttons and inputs use an 8px radius. This card uses 12px.</p>
        </div>
    </section>

    <section class="mb-space-48 max-w-full" aria-labelledby="helpers-heading">
        <h2 id="helpers-heading" class="mb-space-16 text-h2 font-semibold">Money, dates and CSV</h2>
        <div class="max-w-full rounded-card border border-border bg-surface p-space-16 shadow-sm sm:p-space-24">
            <p class="text-body font-normal">Amount <x-money :kobo="12500000" /></p>
            <p class="mt-space-8 text-body font-normal">Zero <x-money :kobo="0" /></p>
            <p class="mt-space-8 text-body font-normal">
                Date
                <x-date value="2026-10-05" source-timezone="Africa/Lagos" />
            </p>
            <p class="mt-space-8 text-body font-normal">
                Date and time
                <x-date value="2026-10-05 14:30:00" mode="datetime" source-timezone="Africa/Lagos" />
            </p>
            <p class="mt-space-8 text-body font-normal">Missing <x-date :value="null" /></p>
            <p class="mt-space-8 font-mono text-mono font-medium">CSV {{ \App\Support\CsvSafe::cell('=1+1') }}</p>
            <p class="mt-space-8 text-small font-normal text-muted">Amounts are integer kobo. Dates use Africa/Lagos. A formula-like export cell starts with an apostrophe.</p>
        </div>
    </section>
@endsection
