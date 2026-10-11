<a
    href="{{ $href }}"
    data-bell="live"
    aria-label="{{ $unread_count > 99 ? 'Notifications, 99+ unread' : 'Notifications, '.$unread_count.' unread' }}"
    class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-text hover:bg-primary-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
>
    <x-ui.icon name="bell" class="size-5" />
    @if ($unread_count > 0)
        <span class="absolute end-1 top-1 inline-flex min-h-4 min-w-4 items-center justify-center rounded-badge bg-danger-bg px-1 text-small font-semibold text-danger-text">{{ \App\Services\NotificationService::badge((int) $unread_count) }}</span>
    @endif
</a>
