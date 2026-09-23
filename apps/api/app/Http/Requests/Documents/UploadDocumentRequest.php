<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,txt',
                'max:10240', // 10 MB max
            ],
            'document_type' => [
                'required',
                'string',
                'in:invoice,receipt,statement,contract,other',
            ],
            'auto_ocr' => ['nullable', 'boolean'],
        ];
    }
}
