@extends('layouts.app')
@section('title', $tenant->name)
@section('content')
<a href="{{ route('tenants.index') }}">← Tenants</a>
<p class="eyebrow">TENANT WORKSPACE</p><h1>{{ $tenant->name }}</h1>
<p>Routing context: <code>{{ $tenant->context }}</code></p>
<section class="panel"><h2>PBX inventory</h2><p>View accounts in this routing context. Tenant administrators can manage PJSIP records when provisioning is enabled. Credentials are never displayed.</p>
<div class="actions">@foreach (['endpoints' => 'Endpoints', 'auths' => 'Authentication', 'aors' => 'AORs'] as $resource => $label)<a class="button secondary" href="{{ route('tenants.inventory', [$tenant, $resource]) }}">{{ $label }}</a>@endforeach</div>
<p class="muted">New tenant contexts require dialplan routing before calls can be made. Registering this workspace changes administration metadata only.</p>
</section>
@can('update', $tenant)
<section class="panel form-panel"><h2>Tenant name</h2><form method="post" action="{{ route('tenants.update', $tenant) }}">@csrf @method('PATCH')
<label for="name">Name</label><input id="name" name="name" value="{{ old('name', $tenant->name) }}" maxlength="120" required><button type="submit">Save name</button>
</form></section>
@endcan
@can('manageMembers', $tenant)
<section class="panel"><h2>Tenant access</h2><p>Platform administrators can access every tenant. Tenant administrators may rename their assigned tenant; viewers have read-only access. Membership changes are restricted to platform administrators.</p>
@if ($members->isNotEmpty())
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead><tbody>
@foreach ($members as $member)<tr><td>{{ $member->name }}</td><td>{{ $member->email }}</td><td>{{ ucfirst($member->pivot->role) }}</td><td><form method="post" action="{{ route('tenants.members.destroy', [$tenant, $member]) }}" onsubmit="return confirm('Remove this tenant membership?')">@csrf @method('DELETE')<button class="secondary" type="submit">Remove access</button></form></td></tr>@endforeach
</tbody></table></div>@include('tenants.pagination', ['paginator' => $members])
@else<p>No tenant-specific memberships have been assigned.</p>@endif
<form method="post" action="{{ route('tenants.members.store', $tenant) }}" class="form-panel">@csrf
<h3>Assign or update access</h3><label for="email">Existing account email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required>
<label for="role">Role</label><select id="role" name="role"><option value="viewer" @selected(old('role') === 'viewer')>Viewer</option><option value="admin" @selected(old('role') === 'admin')>Tenant administrator</option></select><button type="submit">Save access</button>
</form>
</section>
@endcan
@endsection
