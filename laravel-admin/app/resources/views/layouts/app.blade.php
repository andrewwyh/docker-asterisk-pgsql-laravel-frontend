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
        <a href="{{ route('tenants.index') }}">Tenants</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="secondary" type="submit">Sign out</button></form>
    @endauth
</header>
<main>
@if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
@if ($errors->any())<div class="error" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main>
</body>
</html>
