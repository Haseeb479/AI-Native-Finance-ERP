<?php

namespace App\Http\Requests\Journal;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('orgId') ?? $this->route('organization');

        return [
            'entry_date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:2'],
            'lines.*.account_id' => [
                'required_with:lines',
                'uuid',
                Rule::exists('accounts', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at'),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
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
