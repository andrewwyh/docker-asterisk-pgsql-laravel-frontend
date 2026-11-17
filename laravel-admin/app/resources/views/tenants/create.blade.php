@extends('layouts.app')
@section('title', 'Register tenant')
@section('content')
<a href="{{ route('tenants.index') }}">← Tenants</a>
<h1>Register tenant</h1>
<section class="panel form-panel">
<form method="post" action="{{ route('tenants.store') }}">
@csrf
<label for="name">Tenant name</label><input id="name" name="name" value="{{ old('name') }}" maxlength="120" required>
<label for="context">Routing context</label><input id="context" name="context" value="{{ old('context') }}" pattern="[a-z][a-z0-9-]*" maxlength="32" placeholder="abc" required>
<p class="muted">Start with a lowercase letter; use lowercase letters, numbers, and hyphens. The context is permanent. An account for extension 100 in context abc will use the SIP identity 100_abc.</p>
<label class="checkbox"><input type="checkbox" name="confirm_context_ownership" value="1" required @checked(old('confirm_context_ownership'))> I confirm this context belongs to this tenant. Existing PBX accounts in this context will be visible to its members.</label>
<p>Registering a tenant does not rename existing accounts or activate its dialplan.</p>
<button type="submit">Register tenant</button>
</form>
</section>
@endsection
