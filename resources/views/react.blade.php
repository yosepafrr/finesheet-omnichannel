<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- CSRF Token untuk axios --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Finesheet</title>

    {{-- MATERIAL SYMBOLS --}}
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded"
        rel="stylesheet"
    />
    {{-- Injeksi User yang sedang Login untuk Echo & Multi-Tenant --}}
    <script>
        window.authUser = @json(auth()->user());
    </script>

    @php
        $isLocalDevMachine = in_array(request()->getHost(), ['localhost', '127.0.0.1']);
        if (!$isLocalDevMachine) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('vite.hot'));
        }
    @endphp
    @viteReactRefresh
    @vite([
        'resources/css/app.css',
        'resources/js/app.jsx'
    ])

</head>

<body class="bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-colors duration-300">

    <div id="app"></div>

</body>

</html>