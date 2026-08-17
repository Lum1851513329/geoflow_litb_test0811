<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\PromptPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StorePromptPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $preset = $this->route('preset');

        if (! $admin instanceof Admin) {
            return false;
        }

        return $preset instanceof PromptPreset
            ? Gate::forUser($admin)->allows('update', $preset)
            : Gate::forUser($admin)->allows('create', PromptPreset::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $preset = $this->route('preset');
        $admin = $this->user('admin');

        return [
            'preset_key' => [
                'required', 'string', 'max:150', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('prompt_presets', 'preset_key')->ignore($preset instanceof PromptPreset ? $preset->id : null),
            ],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_system' => ['sometimes', 'boolean', Rule::prohibitedIf(! ($admin instanceof Admin && $admin->isSuperAdmin()))],
            'is_enabled' => ['sometimes', 'boolean'],
            'matching_rules' => ['present', 'array'],
            'generation_policy' => ['present', 'array'],
            'conflict_policy' => ['nullable', 'array'],
            'blueprint_template' => ['nullable', 'array'],
            'blueprint_template.target_length' => ['required_with:blueprint_template', 'integer', 'min:1'],
            'blueprint_template.sections' => ['required_with:blueprint_template', 'array', 'min:1'],
            'blueprint_template.sections.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]*$/', 'distinct'],
            'blueprint_template.sections.*.heading' => ['required', 'string', 'max:200'],
            'blueprint_template.sections.*.purpose' => ['required', 'string', 'max:500'],
            'blueprint_template.sections.*.questions_to_answer' => ['required', 'array', 'min:1'],
            'blueprint_template.sections.*.questions_to_answer.*' => ['required', 'string'],
            'blueprint_template.sections.*.minimum_words' => ['required', 'integer', 'min:1'],
            'blueprint_template.sections.*.cta_allowed' => ['required', 'boolean'],
            'blueprint_template.sections.*.required_fact_ids' => ['nullable', 'array'],
            'blueprint_template.sections.*.required_fact_ids.*' => ['string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'blueprint_template.required_entities' => ['nullable', 'array'],
            'blueprint_template.required_entities.*' => ['string'],
            'blueprint_template.show_references_section' => ['nullable', 'boolean'],
            'default_brief_fields' => ['nullable', 'array'],
            'default_brief_fields.market_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'default_brief_fields.language_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/'],
            'default_brief_fields.audience' => ['nullable', 'string', 'max:500'],
            'default_brief_fields.business_goal' => ['nullable', 'string', 'max:500'],
            'default_brief_fields.content_type' => ['nullable', 'string', 'max:50'],
            'default_brief_fields.cta_strength' => ['nullable', 'string', 'max:30'],
            'change_note' => ['nullable', 'string', 'max:2000'],
            'module_versions' => ['required', 'array', 'min:1', 'max:100'],
            'module_versions.*.prompt_module_version_id' => [
                'required', 'integer', 'distinct', 'exists:prompt_module_versions,id',
            ],
            'module_versions.*.sort_order' => ['sometimes', 'integer', 'min:1', 'max:100', 'distinct'],
            'module_versions.*.is_required' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $decoded = [];
        foreach (['matching_rules', 'generation_policy', 'conflict_policy', 'blueprint_template', 'default_brief_fields'] as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                $decoded[$field] = null;

                continue;
            }
            try {
                $decoded[$field] = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded[$field] = $value;
            }
        }
        $this->merge($decoded);
    }
}
