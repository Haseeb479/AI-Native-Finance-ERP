<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_year' => ['required', 'integer', 'between:2000,2100'],
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
