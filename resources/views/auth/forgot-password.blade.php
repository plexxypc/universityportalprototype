<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Forgot password — {{ config('portal.institution.name') }}</title>
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
                <h1 class="mt-space-12 text-center text-h2 font-semibold text-text">Forgot password</h1>
                <p class="mt-space-4 text-center text-body font-normal text-muted">{{ config('portal.institution.name') }}</p>

                <x-card class="mt-space-24">
                    @if (session('status'))
                        <p role="status" class="mb-space-16 text-body font-normal text-text">{{ session('status') }}</p>
                    @endif
                    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-space-16">
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
                            />
                        </x-field>

                        <x-button type="submit" class="w-full">Send reset link</x-button>
                    </form>
                </x-card>

                <p class="mt-space-16 text-center text-body font-normal">
                    <a href="{{ route('login') }}" class="font-semibold text-primary underline">Back to sign in</a>
                </p>
            </div>
        </main>
    </body>
</html>
