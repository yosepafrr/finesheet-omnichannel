<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- CSRF Token untuk axios --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Finesheet | Platform Operasional E-commerce</title>
    <meta name="description" content="Finesheet membantu seller mengelola toko, produk, pesanan, stok, HPP, escrow, supplier, iklan, dan profit dalam satu sistem.">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Finesheet">
    <meta property="og:title" content="Finesheet | Platform Operasional E-commerce">
    <meta property="og:description" content="Kelola operasi dan profit toko Shopee serta TikTok Shop dalam satu sistem.">
    <meta property="og:url" content="https://finesheet.id">
    <link rel="canonical" href="https://finesheet.id">
    <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@@type": "SoftwareApplication",
            "name": "Finesheet",
            "url": "https://finesheet.id",
            "applicationCategory": "BusinessApplication",
            "operatingSystem": "Web",
            "description": "Platform operasional dan analisis e-commerce untuk seller di Indonesia.",
            "author": {
                "@@type": "Person",
                "name": "Yosep Adriana Fauzi R",
                "email": "developer@finesheet.id",
                "address": {
                    "@@type": "PostalAddress",
                    "addressCountry": "ID"
                }
            }
        }
    </script>

    {{-- MATERIAL SYMBOLS --}}
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded"
        rel="stylesheet"
    />
    {{-- Injeksi User yang sedang Login untuk Echo & Multi-Tenant --}}
    <script>
        window.authUser = @json(auth()->user());
        window.flashMessages = @json([
            'success' => session('success'),
            'error' => session('error'),
        ]);
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
