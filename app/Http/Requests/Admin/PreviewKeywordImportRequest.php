<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class PreviewKeywordImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('admin')->user() instanceof Admin;
    }

    public function rules(): array
    {
        return [
            'workbook' => ['required', 'file', 'mimes:xlsx', 'max:'.(int) config('keyword_intelligence.import.max_file_size_kb', 20480)],
            'source_type' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['string', 'in:keyword,clicks,impressions,ctr,position,country,language,date_range,cost,conversions'],
        ];
    }

    public function attributes(): array
    {
        return __('admin.keyword_intelligence.validation.attributes');
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $mapping = $this->input('mapping', []);
            if (! is_array($mapping)) {
                return;
            }
            $targets = array_values(array_filter($mapping, 'is_string'));
            if (count($targets) !== count(array_unique($targets))) {
                $validator->errors()->add('mapping', __('admin.keyword_intelligence.validation.duplicate_mapping'));
            }
        }];
    }
}
