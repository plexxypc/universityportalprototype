@extends('layouts.app')

@section('title', 'Email template preview')

@section('content')
    <header class="mb-space-32 max-w-full">
        <p class="text-small font-normal text-muted">Local only</p>
        <h1 class="text-display font-bold">Email templates</h1>
        <p class="mt-space-8 max-w-full text-body font-normal text-muted">
            Branded HTML and the plain-text twin. Nothing is sent from this page.
        </p>
        <p class="mt-space-12">
            <x-button variant="link" href="{{ url('/design-preview') }}">Design preview</x-button>
        </p>
    </header>

    @foreach ($previews as $preview)
        <section class="mb-space-48 max-w-full" aria-labelledby="email-{{ $preview['name'] }}">
            <h2 id="email-{{ $preview['name'] }}" class="mb-space-8 text-h2 font-semibold">{{ $preview['name'] }}</h2>
            <p class="mb-space-16 text-body font-normal text-muted">{{ $preview['subject'] }}</p>
            <iframe
                title="{{ $preview['name'] }} html"
                srcdoc="{{ $preview['html'] }}"
                class="mb-space-16 h-96 w-full max-w-full rounded-card border border-border bg-surface"
            ></iframe>
            <pre class="max-w-full overflow-x-auto whitespace-pre-wrap rounded-card border border-border bg-surface p-space-16 text-small text-text">{{ $preview['text'] }}</pre>
        </section>
    @endforeach
@endsection
