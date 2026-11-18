@extends('layouts.app')
@section('title', ($record ? 'Edit ' : 'Create ').($resource === 'endpoints' ? 'SIP account' : $resource))
@section('content')
<a href="{{ route('tenants.inventory', [$tenant, $resource]) }}">← {{ $tenant->name }} · {{ $resource }}</a>
<h1>{{ $record ? 'Edit '.$record->id : ($resource === 'endpoints' ? 'Create SIP account' : 'Create '.$resource) }}</h1>
<p>Context: <code>{{ $tenant->context }}</code>. @if($record)Record identities and endpoint references are preserved.@else Identities receive the suffix <code>_{{ $tenant->context }}</code>.@endif</p>
@if (!$record && $resource === 'endpoints')<p>The account creates an endpoint, authentication record, and AOR together. Use the full identity as the phone's SIP username and authentication username. Dialplan routes will be configured in a later phase.</p>@endif
@if($record && $resource === 'endpoints')<p>Authentication: <code>{{ $record->auth ?? '—' }}</code> · AORs: <code>{{ $record->aors ?? '—' }}</code></p>@endif
<section class="panel form-panel">
<form method="post" action="{{ $record ? route('pjsip.update', [$tenant, $resource, $record->id]) : route('pjsip.store', [$tenant, $resource]) }}">
@csrf
@if($record)@method('PATCH')<input type="hidden" name="version_token" value="{{ $token }}">
@else
<label for="resource_key">{{ $resource === 'endpoints' ? 'Local extension' : 'Record name' }}</label>
<input id="resource_key" name="resource_key" value="{{ old('resource_key') }}" maxlength="{{ 39 - strlen($tenant->context) }}" pattern="{{ $resource === 'endpoints' ? '[0-9]+' : '[a-z0-9][a-z0-9-]*' }}" required>
@if($resource !== 'endpoints')
<label for="endpoint_id">Attach to endpoint</label><select id="endpoint_id" name="endpoint_id" required><option value="">Choose an endpoint</option>@foreach($endpoints as $endpointId)<option value="{{ $endpointId }}" @selected(old('endpoint_id') === $endpointId)>{{ $endpointId }}</option>@endforeach</select>
<p class="muted">The reference is added to this endpoint in the same transaction.</p>
@endif
@endif
@if($resource === 'auths' && !$record)<p class="muted">Leave the username blank to use the generated record identity as the SIP authentication username.</p>@endif
@if($resource === 'auths')<p>Authentication type: <code>{{ $record->auth_type ?? 'userpass' }}</code></p>@endif
@php
    $fields = App\Services\PjsipManager::FIELDS[$resource];
    if (!$record && $resource === 'endpoints') { $fields = array_merge($fields, App\Services\PjsipManager::FIELDS['aors']); }
    $defaults = !$record ? ['transport' => config('realtime.transports')[0] ?? '', 'allow' => 'ulaw,alaw', 'disallow' => 'all', 'max_contacts' => 1, 'direct_media' => 'no', 'rtp_symmetric' => 'yes', 'force_rport' => 'yes', 'rewrite_contact' => 'yes', 'remove_existing' => 'yes'] : [];
    $booleanFields = ['direct_media', 'rtp_symmetric', 'force_rport', 'rewrite_contact', 'remove_existing'];
    $numberFields = ['max_contacts', 'default_expiration', 'minimum_expiration', 'maximum_expiration', 'qualify_frequency', 'qualify_timeout'];
@endphp
@foreach($fields as $field)
@php($value = old($field, $record->{$field} ?? $defaults[$field] ?? ''))
<label for="{{ $field }}">{{ ucfirst(str_replace('_', ' ', $field)) }}</label>
@if(in_array($field, $booleanFields, true))
<select id="{{ $field }}" name="{{ $field }}"><option value="">Use PBX default</option>@foreach(['yes' => 'Yes', 'no' => 'No'] as $option => $label)<option value="{{ $option }}" @selected((string)$value === $option)>{{ $label }}</option>@endforeach @if($value !== '' && $value !== null && !in_array((string)$value, ['yes','no'], true))<option value="{{ $value }}" selected>Existing value: {{ $value }}</option>@endif</select>
@elseif($field === 'transport')
<select id="transport" name="transport"><option value="">Use PBX default</option>@foreach(config('realtime.transports') as $transport)<option value="{{ $transport }}" @selected($value === $transport)>{{ $transport }}</option>@endforeach @if($value && !in_array($value, config('realtime.transports'), true))<option value="{{ $value }}" selected>Existing: {{ $value }}</option>@endif</select>
@else
<input id="{{ $field }}" name="{{ $field }}" type="{{ in_array($field, $numberFields, true) ? 'number' : 'text' }}" @if(in_array($field, $numberFields, true)) step="{{ $field === 'qualify_timeout' ? '0.1' : '1' }}" @else maxlength="{{ ['username' => 40, 'realm' => 255, 'callerid' => 40, 'allow' => 200, 'disallow' => 200][$field] ?? 255 }}" @endif value="{{ $value }}">
@endif
@endforeach
@if(!$record && in_array($resource, ['auths', 'endpoints'], true))
<label for="password">SIP password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="80" required>
<label for="password_confirmation">Confirm SIP password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="80" required>
<p class="muted">Use 12–80 characters. The password is stored in the format required by Asterisk.</p>
@elseif($record && $resource === 'auths')
@if($record->auth_type === 'userpass')
<label for="password">SIP password</label><input id="password" name="password" type="text" value="{{ $record->password }}" autocomplete="off" spellcheck="false" autocapitalize="off">
<p class="muted">Use 12–80 characters for a new password. Leave this field blank to keep the current password.</p>
@else<p class="muted">Credential replacement for this authentication type requires a dedicated workflow. Existing credentials are preserved.</p>@endif
@endif
<p class="muted">Blank optional settings use the PBX default. Unlisted settings are preserved. Configure codecs and transport names to match the PBX.</p>
<button type="submit">{{ $record ? 'Save changes' : 'Create' }}</button>
</form>
</section>
@if($record)<a href="{{ route('pjsip.delete', [$tenant, $resource, $record->id]) }}">Review deletion</a>@endif
@endsection
