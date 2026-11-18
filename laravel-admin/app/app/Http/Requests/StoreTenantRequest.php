<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Tenant::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'context' => ['required', 'string', 'max:32', 'regex:/\A[a-z][a-z0-9-]*\z/', Rule::notIn(['general', 'globals']), 'unique:sqlite.tenants,context'],
            'confirm_context_ownership' => ['required', 'accepted'],
        ];
    }
}
