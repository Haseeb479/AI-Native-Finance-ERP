<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreatePurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');

        return [
            'vendor_id' => [
                'required',
                'uuid',
                Rule::exists('vendors', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'vendor_invoice_ref' => ['nullable', 'string', 'max:100'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'wht_rate' => ['nullable', 'numeric', 'between:0,100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_post' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id' => [
                'required',
                'uuid',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
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
