@extends('layouts.app')
@section('title', 'Review deletion')
@section('content')
<a href="{{ route('tenants.inventory', [$tenant, $resource]) }}">← Inventory</a>
<h1>Review deletion</h1>
<section class="panel form-panel">
<p>Delete <strong>{{ $preview['record']->id }}</strong> from context <code>{{ $tenant->context }}</code>.</p>
@if($resource === 'endpoints')<p>This removes the endpoint and the following exclusively linked records together:</p>
<ul>@foreach($preview['related'] as $type => $records)@foreach($records as $id => $version)<li>{{ $type }}: <code>{{ $id }}</code></li>@endforeach @endforeach</ul>
@else<p>This removes the record and its reference from the owning endpoint. The endpoint must retain another record in that reference.</p>@endif
@if($preview['blocked'])<div class="error"><p>Deletion is blocked:</p><ul>@foreach($preview['blocked'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></div>
@else
<form method="post" action="{{ route('pjsip.destroy', [$tenant, $resource, $preview['record']->id]) }}">@csrf @method('DELETE')
<input type="hidden" name="version_token" value="{{ $token }}">
<label for="confirmation">Type {{ $preview['record']->id }} to confirm</label><input id="confirmation" name="confirmation" autocomplete="off" required>
<label class="checkbox"><input type="checkbox" name="confirm_delete" value="1" required> I approve deleting the records shown above.</label>
<button type="submit">Delete reviewed records</button>
</form>
@endif
</section>
@endsection
