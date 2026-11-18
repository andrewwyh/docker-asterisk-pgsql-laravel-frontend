@extends('layouts.app')
@section('title', 'Dialplan · '.$tenant->name)
@section('content')
<a href="{{ route('tenants.show', $tenant) }}">← {{ $tenant->name }}</a>
<h1>Dialplan</h1><p>Context: <code>{{ $tenant->context }}</code></p>
@if(config('realtime.dialplan_writes_enabled'))
@can('manageDialplan', $tenant)
<p><a class="button" href="{{ route('dialplan.create', $tenant) }}">Create route</a></p>
<section class="panel"><h2>Provision local numbers</h2><p>Create missing routes from numeric endpoint identities: <code>100_{{ $tenant->context }}</code> becomes dialable as <code>100</code>. Include 200 for Echo and 201 for Hello world. Existing routes are preserved.</p>
<form method="post" action="{{ route('dialplan.provision', $tenant) }}">@csrf
<label class="checkbox"><input type="checkbox" name="confirm_provision" value="1" required> I approve creating the missing local and test routes.</label><button type="submit">Provision missing routes</button></form></section>
@endcan
@endif
<section class="panel">
@if($routes === null)<p>Dialplan inventory is disabled.</p>
@elseif($routes->isEmpty())<p>No routes in this context yet.</p>
@else
<div class="table-scroll"><table><thead><tr><th>Extension</th><th>Steps</th><th></th></tr></thead><tbody>
@foreach($routes as $route)<tr><td><code>{{ $route->exten }}</code></td><td>{{ $route->step_count }}</td><td>@if(ctype_digit($route->exten) && config('realtime.dialplan_writes_enabled')) @can('manageDialplan', $tenant)<a href="{{ route('dialplan.edit', [$tenant, $route->exten]) }}">Review / edit</a>@endcan @else Read only @endif</td></tr>@endforeach
</tbody></table></div>@include('tenants.pagination', ['paginator' => $routes])
@endif
</section>
<p class="muted">For a new context, run <code>python3 asterisk/scripts/sync-realtime-contexts.py</code> on the PBX host after provisioning. Existing Realtime contexts see route changes immediately. Patterns and applications outside this editor's supported set are preserved.</p>
@endsection
