@extends('layouts.student')

@section('title', 'Fees')

@section('current', 'fees')

@section('preview-bell')
    <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
        <button
            type="button"
            data-bell="preview"
            class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            aria-label="Notifications, 2 unread"
        >
            <x-ui.icon name="bell" class="size-5" />
            <span class="absolute end-1 top-1 inline-flex min-h-4 min-w-4 items-center justify-center rounded-badge bg-danger-bg px-1 text-small font-semibold text-danger-text">2</span>
        </button>
        <div x-show="open" x-cloak role="menu" class="absolute end-0 z-50 mt-space-8 w-72 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-space-12 shadow-lg">
            <p class="text-small font-semibold text-muted">Notifications</p>
            <p class="mt-space-8 text-body font-normal text-text" role="menuitem">Fees are ready to pay.</p>
            <p class="mt-space-8 text-body font-normal text-text" role="menuitem">A result was published.</p>
        </div>
    </div>
@endsection

@section('content')
    <x-breadcrumbs :items="[
        ['label' => 'Home', 'href' => '#home'],
        ['label' => 'Fees'],
    ]" />
    <x-page-header title="Fees" description="Invoices for the current session.">
        <x-slot:action>
            <x-button>Pay now</x-button>
        </x-slot:action>
    </x-page-header>
    <x-stat-card label="Outstanding fees" hint="Current session">
        <x-money :kobo="12500000" />
    </x-stat-card>
    <p class="mt-space-16 max-w-full text-body font-normal text-text">
        This is a sample detail page. The menu, bell and account use demo content.
    </p>
@endsection
