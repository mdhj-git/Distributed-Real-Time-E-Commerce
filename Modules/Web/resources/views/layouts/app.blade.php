<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The public Reverb app key (not the secret). It is the only value the server gives to a page. --}}
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <title>@yield('title') - Shop</title>
    <link rel="stylesheet" href="/ui/css/app.css">
</head>
<body>
<header>
    <nav id="nav" class="nav" aria-label="Main"></nav>
</header>
<main>
    <h1>@yield('title')</h1>
    <div id="error" hidden></div>
    @yield('content')
</main>
<script src="/ui/js/core.js"></script>
@yield('scripts')
</body>
</html>
