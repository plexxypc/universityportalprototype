<div id="student-dashboard" class="flex flex-col gap-space-16">
    <x-page-header
        :title="'Hello, '.$first_name"
        description="Your programme, registration, and fees."
    />

    <x-card title="Programme">
        @if (! $summary['ok'])
            <div x-data x-on:retry.window="window.location.assign('{{ route('student.home') }}')">
                <x-error-state message="Your programme could not be loaded." hint="Reload this page to try again." />
            </div>
        @elseif ($summary['value']['programme'] === '')
            <x-empty-state message="Your programme is not on your record yet." />
        @else
            <dl class="grid gap-space-12">
                <div>
                    <dt class="text-small font-normal text-muted">Programme</dt>
                    <dd class="text-body font-semibold text-text">{{ $summary['value']['programme'] }}</dd>
                </div>
                <div>
                    <dt class="text-small font-normal text-muted">Level</dt>
                    <dd class="text-body font-semibold text-text tabular-nums">{{ $summary['value']['level'] }}</dd>
                </div>
                <div>
                    <dt class="text-small font-normal text-muted">Entry session</dt>
                    <dd class="text-body font-semibold text-text">{{ $summary['value']['entry_session'] }}</dd>
                </div>
            </dl>
        @endif
    </x-card>

    <x-card title="Registration">
        @if (! $registration['ok'])
            <div x-data x-on:retry.window="window.location.assign('{{ route('student.home') }}')">
                <x-error-state message="Registration could not be loaded." hint="Reload this page to try again." />
            </div>
        @elseif ($registration['value']['state'] === 'no_semester')
            <x-empty-state message="There is no active semester." />
        @elseif ($registration['value']['state'] === 'none')
            <x-empty-state message="You have no course registration for this semester." />
        @else
            <p class="text-body font-normal text-text">{{ $registration['value']['semester_name'] }}</p>
            <div class="mt-space-12">
                <x-status-badge :status="$registration['value']['status']" />
            </div>
        @endif
    </x-card>

    <x-card title="Fees due">
        @if (! $fees['ok'])
            <div x-data x-on:retry.window="window.location.assign('{{ route('student.home') }}')">
                <x-error-state message="Fees could not be loaded." hint="Reload this page to try again." />
            </div>
        @elseif ($fees['value']['state'] === 'no_session')
            <x-empty-state message="There is no current session." />
        @elseif ($fees['value']['state'] === 'none')
            <x-empty-state message="You have no invoice for the current session." />
        @else
            <p class="text-small font-normal text-muted">{{ $fees['value']['session_name'] }} · {{ $fees['value']['number'] }}</p>
            <p class="mt-space-8 text-h2 font-semibold text-text">
                <x-money :kobo="$fees['value']['due_kobo']" />
            </p>
            <div class="mt-space-12">
                <x-status-badge :status="$fees['value']['status']" />
            </div>
        @endif
    </x-card>

    <x-card title="Notifications">
        @if (! $notifications['ok'])
            <div x-data x-on:retry.window="window.location.assign('{{ route('student.home') }}')">
                <x-error-state message="Notifications could not be loaded." hint="Reload this page to try again." />
            </div>
        @elseif ($notifications['value']->isEmpty())
            <x-empty-state message="You have no notifications." />
        @else
            <ul class="flex flex-col gap-space-12">
                @foreach ($notifications['value'] as $notification)
                    @php
                        $title = $notification->data['title'] ?? '';
                        $title = is_string($title) ? $title : '';
                    @endphp
                    <li class="text-body font-normal text-text">{{ $title }}</li>
                @endforeach
            </ul>
            <div class="mt-space-16">
                <x-button href="{{ route('student.notifications') }}" variant="secondary">View all</x-button>
            </div>
        @endif
    </x-card>

    <x-card title="Quick actions">
        @if ($actions === [])
            <x-empty-state message="No pages are available yet." />
        @else
            <ul class="flex flex-col gap-space-8">
                @foreach ($actions as $action)
                    <li>
                        <x-button href="{{ route($action['route']) }}" variant="secondary">{{ $action['label'] }}</x-button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
