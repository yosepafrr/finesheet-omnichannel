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
    @viteReactRefresh
    @vite([
        'resources/css/app.css',
        'resources/js/app.jsx'
    ])

</head>

<body>

    <div id="app"></div>

</body>

</html>