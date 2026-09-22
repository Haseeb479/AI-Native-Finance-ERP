<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_entry_id' => ['required', 'uuid', 'exists:journal_entries,id'],
        ];
    }
}
