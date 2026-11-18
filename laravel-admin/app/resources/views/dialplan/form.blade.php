@extends('layouts.app')
@section('title', 'Edit dialplan')
@section('content')
<a href="{{ route('dialplan.index', $tenant) }}">← Dialplan</a><h1>{{ $extension === null ? 'Create route' : 'Edit extension '.$extension }}</h1>
<p>Context: <code>{{ $tenant->context }}</code>. Steps run in the order shown. Finish with Hangup.</p>
@if($steps === null)
<section class="panel"><p>This route contains settings outside the managed editor. Its arguments are withheld and the route is read-only.</p><ul>@foreach($summary as $row)<li>Priority {{ $row['priority'] }}: {{ $row['app'] }}</li>@endforeach</ul></section>
@else
<form data-dialplan-form method="post" action="{{ $extension === null ? route('dialplan.store', $tenant) : route('dialplan.update', [$tenant, $extension]) }}">
@csrf
@if($extension === null)<label for="extension">Local extension</label><input id="extension" name="extension" value="{{ old('extension') }}" pattern="[0-9]+" maxlength="40" required>@else<input type="hidden" name="version_token" value="{{ $token }}">@endif
<datalist id="dialplan-arguments">@foreach($endpoints as $endpoint)<option value="{{ $endpoint }}"></option>@endforeach @foreach(config('realtime.playback_prompts') as $prompt)<option value="{{ $prompt }}"></option>@endforeach</datalist>
<div data-steps>@foreach(old('steps', $steps ?: [['app' => 'Dial'], ['app' => 'Hangup']]) as $stepIndex => $step)@include('dialplan.step')@endforeach</div>
<template data-step-template>@include('dialplan.step', ['stepIndex' => 0, 'step' => ['app' => 'Hangup']])</template>
<div class="actions"><button type="button" class="secondary" data-add-step>Add step</button><button type="submit" name="action" value="preview">Preview changes</button></div>
</form>
@if($extension !== null)<p><a href="{{ route('dialplan.delete', [$tenant, $extension]) }}">Review deletion</a></p>@endif
<script src="{{ asset('dialplan.js') }}" defer></script>
@endif
@endsection
