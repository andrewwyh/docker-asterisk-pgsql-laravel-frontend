<?php

namespace App\Http\Requests;

use App\Services\DialplanManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DialplanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDialplan', $this->route('tenant'));
    }

    public function rules(): array
    {
        if ($this->isMethod('delete')) {
            return ['version_token' => ['required', 'string'], 'confirmation' => ['required', Rule::in([(string) $this->route('extension')])], 'confirm_delete' => ['required', 'accepted']];
        }
        $creating = $this->route('extension') === null;

        return [
            'context' => ['prohibited'], 'id' => ['prohibited'], 'exten' => ['prohibited'],
            'extension' => $creating ? ['required', 'string', 'max:40', 'regex:/\A[0-9]+\z/'] : ['prohibited'],
            'action' => ['required', Rule::in(['preview', 'save'])],
            'version_token' => [! $creating || $this->input('action') === 'save' ? 'required' : 'nullable', 'string'],
            'confirm_save' => $this->input('action') === 'save' ? ['required', 'accepted'] : ['prohibited'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*' => ['array:app,argument,timeout'],
            'steps.*.app' => ['required', Rule::in(DialplanManager::APPLICATIONS)],
            'steps.*.argument' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'steps.*.timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
