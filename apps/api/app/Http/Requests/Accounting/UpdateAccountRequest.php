<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');
        $accountId = $this->route('accountId') ?? $this->route('account');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'account_group_id' => ['nullable', 'integer', 'exists:account_groups,id'],
            'parent_account_id' => [
                'nullable',
                'uuid',
                Rule::notIn([$accountId]), // An account cannot be its own parent
                Rule::exists('accounts', 'id')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId)->whereNull('deleted_at');
                }),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'is_reconcilable' => ['sometimes', 'boolean'],
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
