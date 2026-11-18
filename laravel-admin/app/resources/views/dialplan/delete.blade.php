@extends('layouts.app')
@section('title', 'Review route deletion')
@section('content')
<a href="{{ route('dialplan.index', $tenant) }}">← Dialplan</a><h1>Delete extension {{ $extension }}</h1><p>Context: <code>{{ $tenant->context }}</code>. This removes every priority of this extension only.</p>
@if($steps === null)<p>This route is read-only in the managed editor.</p>
@else
<section class="panel"><ul>@foreach($summary as $row)<li>Priority {{ $row['priority'] }}: {{ $row['app'] }}</li>@endforeach</ul></section>
<form method="post" action="{{ route('dialplan.destroy', [$tenant, $extension]) }}">@csrf @method('DELETE')<input type="hidden" name="version_token" value="{{ $token }}">
<label for="confirmation">Type {{ $extension }} to confirm</label><input id="confirmation" name="confirmation" autocomplete="off" required>
<label class="checkbox"><input type="checkbox" name="confirm_delete" value="1" required> I approve removing this route.</label><button type="submit">Delete reviewed route</button></form>
@endif
@endsection
