<?php

namespace App\Http\Requests\Sales;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'ntn' => ['nullable', 'string', 'max:30'],
            'strn' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
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
