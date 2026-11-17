@extends('layouts.app')
@section('title', ucfirst($resource).' · '.$tenant->name)
@section('content')
<a href="{{ route('tenants.show', $tenant) }}">← {{ $tenant->name }}</a>
<p class="eyebrow">READ-ONLY PBX INVENTORY</p><h1>{{ $resource === 'auths' ? 'Authentication' : ucfirst($resource) }}</h1>
<p>Context: <code>{{ $tenant->context }}</code></p>
<nav class="actions" aria-label="PBX resources">@foreach (['endpoints' => 'Endpoints', 'auths' => 'Authentication', 'aors' => 'AORs'] as $key => $label)<a href="{{ route('tenants.inventory', [$tenant, $key]) }}" @if($resource === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
<section class="panel">
@if ($inventoryStatus === 'disabled')<p>PBX inventory is disabled. A platform administrator can configure the database connection.</p>
@elseif ($inventoryStatus === 'unavailable')<p role="alert">PBX inventory is temporarily unavailable. Please try again later.</p>
@elseif ($records->isEmpty())<p>No {{ $resource }} are available for this tenant.</p>
@else
<div class="table-scroll"><table><thead><tr>@foreach ($columns as $column)<th>{{ ucfirst(str_replace('_', ' ', $column)) }}</th>@endforeach</tr></thead><tbody>
@foreach ($records as $record)<tr>@foreach ($columns as $column)<td>{{ $record->{$column} ?? '—' }}</td>@endforeach</tr>@endforeach
</tbody></table></div>
@include('tenants.pagination', ['paginator' => $records])
@endif
</section>
@if ($resource !== 'endpoints')<p class="muted">Only records referenced by this tenant's endpoints are included. Records shared with another context are omitted.</p>@endif
@endsection
