<x-filament-panels::page>
    @if ($failed)
        <div x-data x-on:retry.window="window.location.assign('{{ url('/staff/notifications') }}')">
            <x-error-state
                message="Notifications could not be loaded."
                hint="Reload this page to try again."
            />
        </div>
    @elseif ($notifications->isEmpty())
        <x-empty-state message="You have no notifications." />
    @else
        @if ($unread_count > 0)
            <div class="mb-4">
                <x-filament::button wire:click="markAll" color="gray">
                    Mark all as read
                </x-filament::button>
            </div>
        @endif
        <ul class="flex max-w-full flex-col gap-3">
            @foreach ($notifications as $notification)
                @php
                    $title = $notification->data['title'] ?? '';
                    $message = $notification->data['message'] ?? '';
                    $title = is_string($title) ? $title : '';
                    $message = is_string($message) ? $message : '';
                    $path = \App\Services\NotificationService::relativePath($notification->data['link'] ?? null);
                @endphp
                <li class="max-w-full rounded-lg border p-4" wire:key="notification-{{ $notification->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($path !== null)
                                <a href="{{ $path }}" class="font-semibold underline">{{ $title }}</a>
                            @else
                                <p class="font-semibold">{{ $title }}</p>
                            @endif
                            <p class="mt-2">{{ $message }}</p>
                            <p class="mt-2 text-sm">{{ \App\Support\Dates::dateTime($notification->created_at) }}</p>
                        </div>
                        @if ($notification->read_at === null)
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="markRead({{ (int) $notification->id }})"
                            >
                                Mark as read
                            </x-filament::button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($notifications->hasPages())
            <div class="mt-4 flex gap-2">
                @if ($notifications->previousPageUrl() !== null)
                    <x-filament::button tag="a" href="{{ $notifications->previousPageUrl() }}" color="gray">Previous</x-filament::button>
                @endif
                @if ($notifications->nextPageUrl() !== null)
                    <x-filament::button tag="a" href="{{ $notifications->nextPageUrl() }}" color="gray">Next</x-filament::button>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
