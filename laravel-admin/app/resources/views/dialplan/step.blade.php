<div class="panel" data-step>
<strong>Priority <span data-position>{{ $stepIndex + 1 }}</span></strong>
<label>Application<select data-field="app" name="steps[{{ $stepIndex }}][app]">@foreach(App\Services\DialplanManager::APPLICATIONS as $app)<option value="{{ $app }}" @selected(($step['app'] ?? 'Hangup') === $app)>{{ $app }}</option>@endforeach</select></label>
<label>Argument<input data-field="argument" name="steps[{{ $stepIndex }}][argument]" value="{{ $step['argument'] ?? '' }}" list="dialplan-arguments" maxlength="255"></label>
<p class="muted">Dial: select a tenant endpoint. Playback: select a permitted prompt. Wait: seconds (0–30). Leave blank for Answer, Echo, and Hangup.</p>
<label>Dial timeout (seconds)<input data-field="timeout" type="number" min="1" max="120" name="steps[{{ $stepIndex }}][timeout]" value="{{ $step['timeout'] ?? 30 }}"></label>
<div class="actions"><button type="button" class="secondary" data-step-action="up">Move up</button><button type="button" class="secondary" data-step-action="down">Move down</button><button type="button" class="secondary" data-step-action="remove">Remove step</button></div>
</div>
