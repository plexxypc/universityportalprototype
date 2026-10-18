@extends('layouts.student')

@section('title', 'Account settings')

@section('current', 'more')

@section('content')
    <x-page-header title="Account settings" description="Your sign-in details and password." />

    <x-card title="Your account">
        <dl class="grid gap-space-12 sm:grid-cols-2">
            <div>
                <dt class="text-small font-normal text-muted">Name</dt>
                <dd class="text-body font-semibold text-text">{{ $full_name }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Email</dt>
                <dd class="text-body font-semibold text-text">{{ $student->user?->email }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Matric number</dt>
                <dd class="text-body font-semibold text-text tabular-nums">{{ $student->matric_no }}</dd>
            </div>
        </dl>
    </x-card>

    <x-card class="mt-space-16" title="Password">
        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-space-16">
            @csrf
            <x-field name="current_password" label="Current password" :error="$errors->first('current_password')" required>
                <x-password-input name="current_password" autocomplete="current-password" />
            </x-field>
            <x-field name="password" label="New password" :error="$errors->first('password')" required>
                <x-password-input name="password" autocomplete="new-password" />
            </x-field>
            <x-field name="password_confirmation" label="Confirm new password" :error="$errors->first('password_confirmation')" required>
                <x-password-input name="password_confirmation" autocomplete="new-password" />
            </x-field>
            <div>
                <x-button type="submit">Change password</x-button>
            </div>
        </form>
    </x-card>
@endsection
