<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Overview') · Asterisk Administration</title>
    <link rel="stylesheet" href="{{ asset('admin.css') }}">
</head>
<body>
<header><a class="brand" href="{{ route('dashboard') }}">Asterisk <span>Administration</span></a>
    @auth
        <form method="post" action="{{ route('logout') }}">@csrf<button class="secondary" type="submit">Sign out</button></form>
    @endauth
</header>
<main>@yield('content')</main>
</body>
</html>
