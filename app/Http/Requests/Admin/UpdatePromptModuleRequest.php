<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\PromptModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class UpdatePromptModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $module = $this->route('module');

        return $admin instanceof Admin
            && $module instanceof PromptModule
            && Gate::forUser($admin)->allows('update', $module);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $admin = $this->user('admin');

        return [
            'display_name' => ['required', 'string', 'max:150'],
            'module_type' => ['required', Rule::in([
                PromptModule::TYPE_FOUNDATION,
                PromptModule::TYPE_GEO,
                PromptModule::TYPE_SEO,
                PromptModule::TYPE_CONTENT_TYPE,
                PromptModule::TYPE_PLATFORM,
                PromptModule::TYPE_LANGUAGE_MARKET,
                PromptModule::TYPE_KNOWLEDGE_POLICY,
                PromptModule::TYPE_OUTPUT_FORMAT,
                PromptModule::TYPE_QUALITY_CHECK,
            ])],
            'description' => ['nullable', 'string', 'max:5000'],
            'applicability' => ['nullable', 'array'],
            'default_priority' => ['required', 'integer', 'between:-100000,100000'],
            'is_mandatory' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
            'is_system' => ['sometimes', 'boolean', Rule::prohibitedIf(! ($admin instanceof Admin && $admin->isSuperAdmin()))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('applicability');
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);
        if ($value === '') {
            $this->merge(['applicability' => null]);

            return;
        }

        try {
            $this->merge(['applicability' => json_decode($value, true, 64, JSON_THROW_ON_ERROR)]);
        } catch (\JsonException) {
            $this->merge(['applicability' => $value]);
        }
    }
}
