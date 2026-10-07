<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', config('app.name'))</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen overflow-x-hidden bg-background font-sans text-text antialiased">
        <div class="mx-auto w-full min-w-0 max-w-content px-page-mobile py-page-mobile md:px-page-tablet md:py-page-tablet lg:px-page-desktop lg:py-page-desktop">
            @yield('content')
        </div>
    </body>
</html>
