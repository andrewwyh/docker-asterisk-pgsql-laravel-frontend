@extends('layouts.app')
@section('title', 'Tenants')
@section('content')
<div class="page-heading"><div><p class="eyebrow">WORKSPACES</p><h1>Tenants</h1></div>
@can('create', App\Models\Tenant::class)<a class="button" href="{{ route('tenants.create') }}">Register tenant</a>@endcan</div>
<p class="muted">Each tenant has its own routing context and access permissions.</p>
<section class="panel">
@if ($tenants->isEmpty())<p>No tenants are available to your account.</p>
@else
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Context</th><th></th></tr></thead><tbody>
@foreach ($tenants as $tenant)<tr><td>{{ $tenant->name }}</td><td><code>{{ $tenant->context }}</code></td><td><a href="{{ route('tenants.show', $tenant) }}">Open workspace</a></td></tr>@endforeach
</tbody></table></div>
@include('tenants.pagination', ['paginator' => $tenants])
@endif
</section>
@endsection
