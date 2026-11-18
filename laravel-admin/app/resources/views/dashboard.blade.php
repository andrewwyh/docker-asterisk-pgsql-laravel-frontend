@extends('layouts.app')
@section('content')
<p class="eyebrow">SYSTEM OVERVIEW</p>
<h1>Welcome, {{ auth()->user()->name }}</h1>
<p class="muted">Your administration workspace is ready.</p>
<div class="cards">
    <section class="panel"><h2>Administration</h2><p class="status">Available</p><p>Administrator accounts and sessions use the separate application database.</p></section>
    <section class="panel"><h2>PBX connection</h2><p class="status">{{ ucfirst($realtimeStatus) }}</p><p>Open a tenant workspace to view or manage its SIP accounts. Provisioning is {{ config('realtime.writes_enabled') ? 'enabled for authorized administrators' : 'disabled' }}.</p></section>
</div>
<section class="panel"><h2>Your tenants</h2><p>Open the workspaces assigned to your account.</p><a class="button" href="{{ route('tenants.index') }}">View tenants</a></section>
<section class="panel"><h2>Coming next</h2><p>External SIP trunks and advanced routing.</p></section>
@endsection
