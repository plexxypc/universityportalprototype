<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Change password — {{ config('portal.institution.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen overflow-x-hidden bg-background font-sans text-text antialiased">
        <x-toast-stack />
        <main class="flex min-h-screen items-center justify-center px-4 py-8">
            <div class="w-full min-w-0 max-w-md">
                <img
                    src="{{ asset((string) config('portal.institution.logo')) }}"
                    alt=""
                    width="48"
                    height="48"
                    class="mx-auto size-12"
                >
                <h1 class="mt-space-12 text-center text-h2 font-semibold text-text">Change password</h1>
                <p class="mt-space-4 text-center text-body font-normal text-muted">{{ config('portal.institution.name') }}</p>

                <x-card class="mt-space-24">
                    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-space-16">
                        @csrf
                        <x-field
                            name="current_password"
                            label="Current password"
                            :error="$errors->first('current_password')"
                            required
                        >
                            <x-password-input name="current_password" autocomplete="current-password" />
                        </x-field>

                        <x-field
                            name="password"
                            label="New password"
                            :error="$errors->first('password')"
                            required
                        >
                            <x-password-input name="password" autocomplete="new-password" />
                        </x-field>

                        <x-field
                            name="password_confirmation"
                            label="Confirm new password"
                            :error="$errors->first('password_confirmation')"
                            required
                        >
                            <x-password-input name="password_confirmation" autocomplete="new-password" />
                        </x-field>

                        <x-button type="submit" class="w-full">Change password</x-button>
                    </form>
                </x-card>

                @if (! $must_change_password && filled($home))
                    <p class="mt-space-16 text-center text-body font-normal">
                        <a href="{{ $home }}" class="font-semibold text-primary underline">Back</a>
                    </p>
                @endif
            </div>
        </main>
        @livewireScripts
    </body>
</html>
