<?php

namespace App\Http\Requests\Admin;

class ConfirmKeywordImportRequest extends PreviewKeywordImportRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['workbook']);

        return $rules + [
            'preview_token' => ['required', 'string', 'max:4096'],
            'library_id' => ['required', 'integer', 'exists:keyword_libraries,id'],
            'language_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/'],
            'market_code' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ];
    }
}
