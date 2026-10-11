@props([
    'href',
    'count',
])

@php
    $count = (int) $count;
    $shown = \App\Services\NotificationService::badge($count);
    $label = $count > 99
        ? 'Notifications, 99+ unread'
        : 'Notifications, '.$count.' unread';
@endphp

<a
    href="{{ $href }}"
    data-bell="live"
    aria-label="{{ $label }}"
    class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
>
    <x-ui.icon name="bell" class="size-5" />
    @if ($count > 0)
        <span class="absolute end-1 top-1 inline-flex min-h-4 min-w-4 items-center justify-center rounded-badge bg-danger-bg px-1 text-small font-semibold text-danger-text">{{ $shown }}</span>
    @endif
</a>
