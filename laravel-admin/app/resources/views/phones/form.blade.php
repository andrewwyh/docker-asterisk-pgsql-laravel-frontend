@extends('layouts.app')
@section('title', $phone->exists ? 'Edit phone' : 'Add phone')
@section('content')
<a href="{{ route('tenants.phones.index', $tenant) }}">← Phones</a>
<h1>{{ $phone->exists ? 'Edit phone' : 'Add phone' }}</h1>
<p>Routing context <code>{{ $tenant->context }}</code></p>
<section class="panel form-panel">
    <form method="post" enctype="multipart/form-data" action="{{ $phone->exists ? route('tenants.phones.update', [$tenant, $phone]) : route('tenants.phones.store', $tenant) }}">
        @csrf
        @if ($phone->exists) @method('PATCH') @endif
        @if ($phone->exists)
            <h2>Provisioning</h2>
            <label for="profile-url">Profile URL</label>
            <input id="profile-url" name="profile_url" type="url" value="{{ $url }}" maxlength="2048" autocomplete="off">
            <p class="muted">Change the scheme, host, port, or proxy prefix, then Save phone. Keep the private path unchanged. Clear this field to restore the default URL.</p>
            <p class="muted">Treat this URL as a password. Changing it does not configure a reverse proxy or make an address reachable from the handset.</p>
        @endif
        <label for="name">Name</label>
        <input id="name" name="name" value="{{ old('name', $phone->name) }}" maxlength="120" required>
        <label for="mac">MAC address</label>
        <input id="mac" name="mac" value="{{ old('mac', $phone->mac) }}" maxlength="17" placeholder="00:11:22:33:44:55" required>
        <label for="endpoint">PJSIP endpoint ID</label>
        <input id="endpoint" name="endpoint" value="{{ old('endpoint', $phone->endpoint) }}" maxlength="128" required>
        <p class="muted"><a href="{{ route('tenants.inventory', [$tenant, 'endpoints']) }}">View endpoints in this context</a></p>
        <label for="profile">{{ $phone->exists ? 'Replace provisioning file (optional)' : 'Provisioning file' }}</label>
        <input id="profile" name="profile" type="file" accept=".xml,.cfg" @required(! $phone->exists)>
        <p class="muted">Upload the XML or CFG provisioning file prepared for this handset and firmware.</p>
        <button type="submit">Save phone</button>
    </form>
    @if ($phone->exists)
        <form method="post" action="{{ route('tenants.phones.destroy', [$tenant, $phone]) }}" onsubmit="return confirm('Remove this phone and deactivate its provisioning URL?')">
            @csrf @method('DELETE')
            <button class="secondary" type="submit">Remove phone</button>
        </form>
    @endif
</section>
@endsection
