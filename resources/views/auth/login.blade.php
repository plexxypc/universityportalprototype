<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign in — {{ config('portal.institution.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen overflow-x-hidden bg-background font-sans text-text antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-8">
            <div class="w-full min-w-0 max-w-md">
                <img
                    src="{{ asset((string) config('portal.institution.logo')) }}"
                    alt=""
                    width="48"
                    height="48"
                    class="mx-auto size-12"
                >
                <h1 class="mt-space-12 text-center text-h2 font-semibold text-text">{{ config('portal.institution.name') }}</h1>
                <p class="mt-space-4 text-center text-body font-normal text-muted">Sign in</p>

                <x-card class="mt-space-24">
                    @if (request()->query('expired') === '1')
                        <p role="status" class="mb-space-16 text-body font-normal text-text">Your session has ended. Sign in to continue.</p>
                    @endif
                    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-space-16">
                        @csrf
                        <x-field
                            name="identifier"
                            label="Matric number or email"
                            :error="$errors->first('identifier')"
                            required
                        >
                            <x-input
                                name="identifier"
                                autocomplete="username"
                                :value="old('identifier')"
                            />
                        </x-field>

                        <x-field
                            name="password"
                            label="Password"
                            :error="$errors->first('password')"
                            required
                        >
                            <x-password-input name="password" />
                        </x-field>

                        <x-button type="submit" class="w-full">Sign in</x-button>
                    </form>
                </x-card>
            </div>
        </main>
    </body>
</html>
