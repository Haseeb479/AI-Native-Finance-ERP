<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class ApproveExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional human-corrected extracted fields (freely structured JSON object)
            'corrected_data' => ['nullable', 'array'],
            'corrected_data.vendor_name' => ['nullable', 'string', 'max:150'],
            'corrected_data.vendor_ntn' => ['nullable', 'string', 'max:30'],
            'corrected_data.vendor_strn' => ['nullable', 'string', 'max:30'],
            'corrected_data.invoice_number' => ['nullable', 'string', 'max:100'],
            'corrected_data.invoice_date' => ['nullable', 'date'],
            'corrected_data.due_date' => ['nullable', 'date'],
            'corrected_data.currency' => ['nullable', 'string', 'size:3'],
            'corrected_data.subtotal' => ['nullable', 'numeric', 'min:0'],
            'corrected_data.sales_tax_amount' => ['nullable', 'numeric', 'min:0'],
            'corrected_data.total_amount' => ['nullable', 'numeric', 'min:0'],
            'corrected_data.line_items' => ['nullable', 'array'],
        ];
    }
}
