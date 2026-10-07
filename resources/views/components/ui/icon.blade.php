@props(['name'])

@php
    $allowed = ['spinner', 'alert', 'eye', 'eye-off', 'chevron-down', 'check', 'x', 'info', 'minus'];

    if (! in_array($name, $allowed, true)) {
        throw new InvalidArgumentException('Unknown icon.');
    }
@endphp

<svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->merge(['class' => 'size-4 shrink-0']) }}
>
    @if ($name === 'spinner')
        <path d="M21 12a9 9 0 1 1-6.219-8.56" />
    @elseif ($name === 'alert')
        <circle cx="12" cy="12" r="10" />
        <line x1="12" x2="12" y1="8" y2="12" />
        <line x1="12" x2="12.01" y1="16" y2="16" />
    @elseif ($name === 'eye')
        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
    @elseif ($name === 'eye-off')
        <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
        <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
        <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
        <line x1="2" x2="22" y1="2" y2="22" />
    @elseif ($name === 'chevron-down')
        <path d="m6 9 6 6 6-6" />
    @elseif ($name === 'check')
        <path d="M20 6 9 17l-5-5" />
    @elseif ($name === 'x')
        <path d="M18 6 6 18" />
        <path d="m6 6 12 12" />
    @elseif ($name === 'info')
        <circle cx="12" cy="12" r="10" />
        <path d="M12 16v-4" />
        <path d="M12 8h.01" />
    @else
        <path d="M5 12h14" />
    @endif
</svg>
