<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', config('portal.institution.name'))</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen overflow-x-hidden bg-background font-sans text-text antialiased">
        @php
            $current = trim($__env->yieldContent('current')) ?: 'home';
        @endphp
        <x-toast-stack />
        <div class="min-h-screen md:grid md:grid-cols-[15rem_minmax(0,1fr)]">
            <x-student.sidebar :current="$current" />
            <div class="flex min-w-0 flex-col">
                <x-student.top-bar>
                    @hasSection('preview-bell')
                        <x-slot:bell>
                            @yield('preview-bell')
                        </x-slot:bell>
                    @endif
                </x-student.top-bar>
                <main class="w-full min-w-0 flex-1 px-page-mobile pt-page-mobile pb-24 md:px-page-tablet md:py-page-tablet lg:px-page-desktop lg:py-page-desktop">
                    <div class="mx-auto w-full min-w-0 max-w-content">
                        @yield('content')
                    </div>
                </main>
            </div>
        </div>
        <x-student.bottom-nav :current="$current" />
        @livewireScripts
    </body>
</html>
