<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreTenantMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower((string) $this->input('email'))]);
    }

    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->route('tenant'));
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'exists:sqlite.users,email'],
            'role' => ['required', Rule::in(['viewer', 'admin'])],
        ];
    }
}
