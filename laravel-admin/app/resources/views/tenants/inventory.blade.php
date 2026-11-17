@extends('layouts.app')
@section('title', ucfirst($resource).' · '.$tenant->name)
@section('content')
<a href="{{ route('tenants.show', $tenant) }}">← {{ $tenant->name }}</a>
<p class="eyebrow">PBX INVENTORY</p><h1>{{ $resource === 'auths' ? 'Authentication' : ucfirst($resource) }}</h1>
<p>Context: <code>{{ $tenant->context }}</code></p>
<nav class="actions" aria-label="PBX resources">@foreach (['endpoints' => 'Endpoints', 'auths' => 'Authentication', 'aors' => 'AORs'] as $key => $label)<a href="{{ route('tenants.inventory', [$tenant, $key]) }}" @if($resource === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
@if(config('realtime.enabled') && config('realtime.writes_enabled'))
@can('managePjsip', $tenant)<p><a class="button" href="{{ route('pjsip.create', [$tenant, $resource]) }}">{{ $resource === 'endpoints' ? 'Create SIP account' : 'Create '.$resource }}</a></p>@endcan
@endif
<section class="panel">
@if ($inventoryStatus === 'disabled')<p>PBX inventory is disabled. A platform administrator can configure the database connection.</p>
@elseif ($inventoryStatus === 'unavailable')<p role="alert">PBX inventory is temporarily unavailable. Please try again later.</p>
@elseif ($records->isEmpty())<p>No {{ $resource }} are available for this tenant.</p>
@else
<div class="table-scroll"><table><thead><tr>@foreach ($columns as $column)<th>{{ ucfirst(str_replace('_', ' ', $column)) }}</th>@endforeach @can('managePjsip', $tenant) @if(config('realtime.writes_enabled'))<th>Manage</th>@endif @endcan</tr></thead><tbody>
@foreach ($records as $record)<tr>@foreach ($columns as $column)<td>{{ $record->{$column} ?? '—' }}</td>@endforeach @can('managePjsip', $tenant) @if(config('realtime.writes_enabled'))<td><a href="{{ route('pjsip.edit', [$tenant, $resource, $record->id]) }}">Edit</a> · <a href="{{ route('pjsip.delete', [$tenant, $resource, $record->id]) }}">Review deletion</a></td>@endif @endcan</tr>@endforeach
</tbody></table></div>
@include('tenants.pagination', ['paginator' => $records])
@endif
</section>
@if ($resource !== 'endpoints')<p class="muted">Only records referenced by this tenant's endpoints are included. Records shared with another context are omitted.</p>@endif
@endsection
