@extends('layouts.student')

@section('title', 'Notifications')

@section('current', 'notifications')

@section('content')
    <x-page-header title="Notifications" description="Updates for your account.">
        @if (! $failed && $unread_count > 0)
            <x-slot:action>
                <form method="POST" action="{{ route('student.notifications.read-all') }}">
                    @csrf
                    <x-button type="submit" variant="secondary">Mark all as read</x-button>
                </form>
            </x-slot:action>
        @endif
    </x-page-header>

    @if ($failed)
        <div x-data x-on:retry.window="window.location.assign('{{ route('student.notifications') }}')">
            <x-error-state
                message="Notifications could not be loaded."
                hint="Reload this page to try again."
            />
        </div>
    @elseif ($notifications->isEmpty())
        <x-empty-state message="You have no notifications." />
    @else
        <ul class="flex max-w-full flex-col gap-space-12">
            @foreach ($notifications as $notification)
                @php
                    $title = $notification->data['title'] ?? '';
                    $message = $notification->data['message'] ?? '';
                    $title = is_string($title) ? $title : '';
                    $message = is_string($message) ? $message : '';
                    $path = \App\Services\NotificationService::relativePath($notification->data['link'] ?? null);
                @endphp
                <li class="max-w-full rounded-card border border-border bg-surface p-space-16 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-space-12">
                        <div class="min-w-0">
                            @if ($path !== null)
                                <a href="{{ $path }}" class="text-body font-semibold text-primary-600 underline-offset-2 hover:underline">{{ $title }}</a>
                            @else
                                <p class="text-body font-semibold text-text">{{ $title }}</p>
                            @endif
                            <p class="mt-space-8 text-body font-normal text-text">{{ $message }}</p>
                            <p class="mt-space-8 text-small font-normal text-muted">{{ \App\Support\Dates::dateTime($notification->created_at) }}</p>
                        </div>
                        @if ($notification->read_at === null)
                            <form method="POST" action="{{ route('student.notifications.read', ['notification' => $notification->id]) }}">
                                @csrf
                                <x-button type="submit" variant="ghost" size="small">Mark as read</x-button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($notifications->hasPages())
            <div class="mt-space-16 flex gap-space-8">
                @if ($notifications->previousPageUrl() !== null)
                    <x-button href="{{ $notifications->previousPageUrl() }}" variant="secondary">Previous</x-button>
                @endif
                @if ($notifications->nextPageUrl() !== null)
                    <x-button href="{{ $notifications->nextPageUrl() }}" variant="secondary">Next</x-button>
                @endif
            </div>
        @endif
    @endif
@endsection
