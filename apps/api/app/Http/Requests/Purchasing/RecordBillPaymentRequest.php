<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RecordBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');

        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
            'bank_account_id' => [
                'nullable',
                'uuid',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
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
