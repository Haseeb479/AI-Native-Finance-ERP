<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('accounts', 'code')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId)->whereNull('deleted_at');
                }),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'account_type_id' => ['required', 'integer', 'exists:account_types,id'],
            'account_group_id' => ['nullable', 'integer', 'exists:account_groups,id'],
            'parent_account_id' => [
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId)->whereNull('deleted_at');
                }),
            ],
            'classification' => ['required', 'string', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'normal_balance' => ['required', 'string', Rule::in(['debit', 'credit'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_reconcilable' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'data' => null,
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
            'errors' => collect($validator->errors()->all())->map(fn ($msg) => [
                'code' => 'VALIDATION_ERROR',
                'message' => $msg,
            ])->values(),
        ], 422));
    }
}
