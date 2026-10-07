@props(['name'])

@php
    $allowed = ['spinner', 'alert', 'eye', 'eye-off', 'chevron-down', 'check', 'x', 'info', 'minus', 'inbox', 'home', 'book', 'card', 'file', 'more', 'bell', 'user'];

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
    @elseif ($name === 'inbox')
        <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
        <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    @elseif ($name === 'home')
        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
        <polyline points="9 22 9 12 15 12 15 22" />
    @elseif ($name === 'book')
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
    @elseif ($name === 'card')
        <rect width="20" height="14" x="2" y="5" rx="2" />
        <line x1="2" x2="22" y1="10" y2="10" />
    @elseif ($name === 'file')
        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
        <polyline points="14 2 14 8 20 8" />
    @elseif ($name === 'more')
        <circle cx="5" cy="12" r="1" />
        <circle cx="12" cy="12" r="1" />
        <circle cx="19" cy="12" r="1" />
    @elseif ($name === 'bell')
        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
    @elseif ($name === 'user')
        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
    @else
        <path d="M5 12h14" />
    @endif
</svg>
