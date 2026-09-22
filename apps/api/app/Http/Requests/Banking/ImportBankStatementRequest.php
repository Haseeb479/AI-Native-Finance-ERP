<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'csv_content' => ['required_without:statement_file', 'string'],
            'statement_file' => ['required_without:csv_content', 'file', 'mimes:csv,txt', 'max:5120'],
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }
}
