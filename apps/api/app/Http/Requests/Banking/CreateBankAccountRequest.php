<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class CreateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_title' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:34'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'branch_code' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
