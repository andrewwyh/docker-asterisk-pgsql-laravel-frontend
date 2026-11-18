@extends('layouts.app')
@section('title', 'Phones')
@section('content')
<a href="{{ route('tenants.show', $tenant) }}">← {{ $tenant->name }}</a>
<div class="page-heading">
    <div><h1>Phones</h1><p>Routing context <code>{{ $tenant->context }}</code></p></div>
    @can('update', $tenant)
        <a class="button" href="{{ route('tenants.phones.create', $tenant) }}">Add phone</a>
    @endcan
</div>
<section class="panel">
    @if ($phones->isEmpty())
        <p>No phones are assigned to this context.</p>
    @else
        <div class="table-scroll">
            <table>
                <thead><tr><th>Name</th><th>MAC address</th><th>SIP endpoint</th>@can('update', $tenant)<th>Actions</th>@endcan</tr></thead>
                <tbody>
                @foreach ($phones as $phone)
                    <tr>
                        <td>{{ $phone->name }}</td><td><code>{{ $phone->mac }}</code></td><td>{{ $phone->endpoint }}</td>
                        @can('update', $tenant)
                            <td>
                                <div class="actions">
                                    <a href="{{ route('tenants.phones.edit', [$tenant, $phone]) }}">Edit and setup</a>
                                    <form method="post" action="{{ route('tenants.phones.reboot', [$tenant, $phone]) }}" onsubmit="return confirm('Reboot this phone now?')">
                                        @csrf
                                        <button class="secondary" type="submit">Reboot</button>
                                    </form>
                                </div>
                            </td>
                        @endcan
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('tenants.pagination', ['paginator' => $phones])
    @endif
</section>
@endsection
