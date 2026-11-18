<?php

namespace App\Http\Requests;

use App\Services\PjsipManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PjsipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePjsip', $this->route('tenant'));
    }

    public function rules(): array
    {
        $resource = $this->route('resource');
        $creating = $this->isMethod('post');
        $rules = [
            'id' => ['prohibited'], 'context' => ['prohibited'], 'auth' => ['prohibited'], 'aors' => ['prohibited'], 'outbound_auth' => ['prohibited'],
        ];
        if ($this->isMethod('delete')) {
            return $rules + ['version_token' => ['required', 'string'], 'confirmation' => ['required', Rule::in([(string) $this->route('record')])], 'confirm_delete' => ['required', 'accepted']];
        }
        if ($creating) {
            $rules['resource_key'] = ['required', 'string', 'max:'.(39 - strlen($this->route('tenant')->context)), $resource === 'endpoints' ? 'regex:/\A[0-9]+\z/' : 'regex:/\A[a-z0-9][a-z0-9-]*\z/'];
            if ($resource !== 'endpoints') {
                $rules['endpoint_id'] = ['required', 'string', 'max:255'];
            }
        } else {
            $rules['version_token'] = ['required', 'string'];
            $rules['resource_key'] = ['prohibited'];
            $rules['endpoint_id'] = ['prohibited'];
        }
        $fieldRules = [
            'transport' => ['nullable', Rule::in(config('realtime.transports'))],
            'allow' => ['nullable', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:,[a-z0-9]+)*\z/'],
            'disallow' => ['nullable', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:,[a-z0-9]+)*\z/'],
            'callerid' => ['nullable', 'string', 'max:40', 'not_regex:/[\x00-\x1f\x7f]/'],
            'direct_media' => ['nullable', Rule::in(['yes', 'no', '0', '1', 'off', 'on', 'false', 'true'])],
            'rtp_symmetric' => ['nullable', Rule::in(['yes', 'no', '0', '1', 'off', 'on', 'false', 'true'])],
            'force_rport' => ['nullable', Rule::in(['yes', 'no', '0', '1', 'off', 'on', 'false', 'true'])],
            'rewrite_contact' => ['nullable', Rule::in(['yes', 'no', '0', '1', 'off', 'on', 'false', 'true'])],
            'username' => ['nullable', 'string', 'max:40', 'not_regex:/[\x00-\x1f\x7f]/'],
            'realm' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            'max_contacts' => ['nullable', 'integer', 'min:0', 'max:100'],
            'default_expiration' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'minimum_expiration' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'maximum_expiration' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'qualify_frequency' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'qualify_timeout' => ['nullable', 'numeric', 'min:0.1', 'max:60'],
            'remove_existing' => ['nullable', Rule::in(['yes', 'no', '0', '1', 'off', 'on', 'false', 'true'])],
        ];
        foreach ($fieldRules as $field => $constraints) {
            if (in_array($field, PjsipManager::FIELDS[$resource], true) || ($creating && $resource === 'endpoints' && in_array($field, PjsipManager::FIELDS['aors'], true))) {
                $rules[$field] = array_merge(['sometimes'], $constraints);
            }
        }
        if ($creating && in_array($resource, ['auths', 'endpoints'], true)) {
            $rules['password'] = ['required', ...PjsipManager::PASSWORD_RULES, 'confirmed'];
        } elseif ($resource === 'auths') {
            $rules['password'] = ['nullable', 'string'];
        }

        return $rules;
    }
}
