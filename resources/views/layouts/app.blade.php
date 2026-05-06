<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" class="rounded-full">

    <title>{{ config('app.name', 'Laravel') }}</title>

    {{-- GOOGLE ICON --}}
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet" />
    <style>
        .material-symbols-rounded {
            font-variation-settings:
                'FILL' 0,
                'wght' 0,
                'GRAD' 0,
                'opsz' 24
        }
    </style>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900">
    
    <div class="flex h-screen overflow-hidden">
        
        <livewire:layout.sidebar />

        <div class="relative flex flex-col flex-1 overflow-y-auto overflow-x-hidden transition-all duration-300">
            
            <livewire:layout.navigation />

            <main class="p-6">
                {{ $slot }}
            </main>
            <livewire:layout.footer />
        </div>

    </div>
        @livewireScripts
</body>

</html>