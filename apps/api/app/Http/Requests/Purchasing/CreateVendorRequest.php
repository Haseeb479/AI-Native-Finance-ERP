<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'strn' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'default_expense_account_id' => [
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'data' => null,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
            'errors' => collect($validator->errors()->all())->map(fn ($msg) => [
                'code' => 'VALIDATION_ERROR',
                'message' => $msg,
            ])->values(),
        ], 422));
    }
}
