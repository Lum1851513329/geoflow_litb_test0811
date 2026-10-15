<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\PromptModule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePromptModuleRequest extends StorePromptModuleVersionRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin
            && Gate::forUser($admin)->allows('create', PromptModule::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $admin = $this->user('admin');

        return array_merge(parent::rules(), [
            'module_key' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:prompt_modules,module_key'],
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
            'is_system' => ['sometimes', 'boolean', Rule::prohibitedIf(! ($admin instanceof Admin && $admin->isSuperAdmin()))],
            'applicability' => ['nullable', 'array'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'default_priority' => ['required', 'integer', 'between:-100000,100000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge($this->decodedJsonFields(['applicability']));
    }
}
