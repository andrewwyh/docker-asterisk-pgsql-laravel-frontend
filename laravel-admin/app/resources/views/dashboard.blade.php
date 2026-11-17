@extends('layouts.app')
@section('content')
<p class="eyebrow">SYSTEM OVERVIEW</p>
<h1>Welcome, {{ auth()->user()->name }}</h1>
<p class="muted">Your administration workspace is ready.</p>
<div class="cards">
    <section class="panel"><h2>Administration</h2><p class="status">Available</p><p>Administrator accounts and sessions use the separate application database.</p></section>
    <section class="panel"><h2>PBX connection</h2><p class="status">{{ ucfirst($realtimeStatus) }}</p><p>PBX connectivity is optional. Account management will be introduced in a later phase.</p></section>
</div>
<section class="panel"><h2>Coming next</h2><p>Tenant workspaces, SIP accounts, and dialplan management.</p></section>
@endsection
