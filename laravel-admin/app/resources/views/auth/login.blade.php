@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<section class="login panel">
    <p class="eyebrow">ADMINISTRATOR ACCESS</p>
    <h1>Sign in</h1>
    <p class="muted">Manage your telephone system.</p>
    @if ($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" maxlength="255" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</section>
@endsection
