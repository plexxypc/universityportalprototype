@php
    $user = auth()->user();
@endphp

@if ($user instanceof \App\Models\User)
    <x-notification-bell
        :href="route('student.notifications')"
        :count="app(\App\Services\NotificationService::class)->unreadCount($user)"
    />
@endif
