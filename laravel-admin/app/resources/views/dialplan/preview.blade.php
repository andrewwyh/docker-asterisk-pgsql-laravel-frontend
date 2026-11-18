@extends('layouts.app')
@section('title', 'Review dialplan changes')
@section('content')
<h1>Review extension {{ $extension }}</h1><p>Context: <code>{{ $tenant->context }}</code>. {{ $creating ? 'Create this route.' : 'Replace the existing priorities with the steps shown below.' }}</p>
<section class="panel"><div class="table-scroll"><table><thead><tr><th>Priority</th><th>Application</th><th>Arguments</th></tr></thead><tbody>@foreach($compiled as $row)<tr><td>{{ $row['priority'] }}</td><td>{{ $row['app'] }}</td><td><code>{{ $row['appdata'] }}</code></td></tr>@endforeach</tbody></table></div></section>
<form method="post" action="{{ $creating ? route('dialplan.store', $tenant) : route('dialplan.update', [$tenant, $extension]) }}">@csrf
@if($creating)<input type="hidden" name="extension" value="{{ $extension }}">@endif
<input type="hidden" name="action" value="save"><input type="hidden" name="version_token" value="{{ $token }}">
@foreach($steps as $index => $step)@foreach(['app','argument','timeout'] as $field)@if(isset($step[$field]))<input type="hidden" name="steps[{{ $index }}][{{ $field }}]" value="{{ $step[$field] }}">@endif @endforeach @endforeach
<label class="checkbox"><input type="checkbox" name="confirm_save" value="1" required> I approve applying these steps to this tenant's route.</label><button type="submit">Apply reviewed route</button>
</form><p><a href="{{ $creating ? route('dialplan.create', $tenant) : route('dialplan.edit', [$tenant, $extension]) }}">Return to editor</a></p>
@endsection
