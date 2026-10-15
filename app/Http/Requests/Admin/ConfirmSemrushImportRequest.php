<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmSemrushImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'preview_token' => ['required', 'string'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'need_review' => ['sometimes', 'boolean'],
            'external_evidence_policy' => ['nullable', 'in:off,on_insufficient'],
            'generation_engine' => ['nullable', 'in:quality_gated,workbuddy'],
            'prompt_id' => ['nullable', 'integer', 'min:1'],
            'external_general_domains' => ['nullable', 'string', 'max:1000'],
            // Positions (0-based) into the preview's accepted-rows list that
            // the admin left checked — anything not sent here was manually
            // excluded (e.g. an unsuitable topic the blocklist missed).
            'keep' => ['required', 'array', 'min:1'],
            'keep.*' => ['integer', 'min:0'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'keep.required' => '请至少勾选一个要导入的主题。',
            'keep.min' => '请至少勾选一个要导入的主题。',
        ];
    }
}
