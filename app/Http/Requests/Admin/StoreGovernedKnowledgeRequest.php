<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\KnowledgeDirectory;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

final class StoreGovernedKnowledgeRequest extends FormRequest
{
    private const POLICY_DIRECTORIES = [
        'policy-compliance',
        'customer-transaction',
        'logistics-fulfilment',
    ];

    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $brandId = $this->integer('brand_id');
        $isUpdate = $this->route('knowledgeBaseId') !== null;

        return [
            'name' => [$isUpdate ? 'required' : 'nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => [$isUpdate ? 'required' : 'nullable', 'string'],
            'file_type' => [$isUpdate ? 'required' : 'nullable', Rule::in(['markdown', 'word', 'text'])],
            'brand_id' => ['required', 'integer', Rule::exists('knowledge_brands', 'id')],
            'site_id' => [
                'required',
                'integer',
                Rule::exists('knowledge_sites', 'id')->where(
                    fn ($query) => $query->where('brand_id', $brandId),
                ),
            ],
            'primary_directory_id' => [
                'required',
                'integer',
                Rule::exists('knowledge_directories', 'id')->where(
                    fn ($query) => $query->where('brand_id', $brandId),
                ),
            ],
            'canonical_document_key' => ['nullable', 'string', 'max:190'],
            'language_code' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/'],
            'authority_level' => [
                'required',
                Rule::in(['first_party', 'regulatory_filing', 'mixed_research', 'third_party']),
            ],
            'source_name' => ['required', 'string', 'max:150'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
            'source_type' => [
                'required',
                Rule::in([
                    'document',
                    'website',
                    'business',
                    'faq',
                    'other',
                    'official_web_page',
                    'compiled_report',
                ]),
            ],
            'business_line' => ['nullable', 'string', 'max:100'],
            'effective_date' => ['nullable', 'date'],
            'retrieved_at' => ['nullable', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high'])],
            'review_status' => ['required', Rule::in(['unreviewed', 'reviewed'])],
            'version_label' => ['nullable', 'string', 'max:100'],
            'lifecycle_status' => ['required', Rule::in(['active', 'superseded'])],
            'generation_eligible' => ['nullable', 'boolean'],
            'citation_required' => ['nullable', 'boolean'],
            'import_action' => ['nullable', Rule::in(['save', 'save_and_chunk'])],
            'knowledge_file' => ['nullable', File::types(['txt', 'md', 'docx'])->max(8 * 1024)],
            'knowledge_files' => ['nullable', 'array', 'max:10'],
            'knowledge_files.*' => ['file', File::types(['txt', 'md', 'docx'])->max(8 * 1024)],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $directory = KnowledgeDirectory::query()->find($this->integer('primary_directory_id'));
            if (! $directory instanceof KnowledgeDirectory) {
                return;
            }

            $isActivePolicy = $this->input('lifecycle_status') === 'active'
                && $this->isPolicyDirectory((string) $directory->path);
            if ($isActivePolicy && trim((string) $this->input('source_url')) === '') {
                $validator->errors()->add('source_url', __('admin.knowledge_bases.error.policy_source_url_required'));
            }
            if ($isActivePolicy && trim((string) $this->input('retrieved_at')) === '') {
                $validator->errors()->add('retrieved_at', __('admin.knowledge_bases.error.policy_retrieved_at_required'));
            }

            $isResearch = $this->isResearchDirectory((string) $directory->path)
                || $this->input('authority_level') === 'mixed_research';
            $isUnreviewedHighRiskResearch = $isResearch
                && $this->input('risk_level') === 'high'
                && $this->input('review_status') === 'unreviewed';
            if ($isUnreviewedHighRiskResearch && $this->boolean('generation_eligible')) {
                $validator->errors()->add('generation_eligible', __('admin.knowledge_bases.error.research_generation_ineligible'));
            }
            if ($isResearch && ! $this->boolean('citation_required', true)) {
                $validator->errors()->add('citation_required', __('admin.knowledge_bases.error.research_citation_required'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
            'knowledge_file.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_file.max' => __('admin.knowledge_bases.error.file_too_large'),
            'knowledge_files.max' => __('admin.knowledge_bases.error.files_limit'),
            'knowledge_files.*.mimes' => __('admin.knowledge_bases.error.file_type_invalid'),
            'knowledge_files.*.max' => __('admin.knowledge_bases.error.file_too_large'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $language = KnowledgeScope::canonicalizeLanguageTag((string) $this->input('language_code'));

        $this->merge([
            'language_code' => $language,
            'source_name' => trim((string) $this->input('source_name')),
            'source_url' => trim((string) $this->input('source_url')),
            'canonical_document_key' => trim((string) $this->input('canonical_document_key')),
            'version_label' => trim((string) $this->input('version_label')),
        ]);
    }

    private function isPolicyDirectory(string $path): bool
    {
        foreach (self::POLICY_DIRECTORIES as $policyDirectory) {
            if ($path === $policyDirectory || str_starts_with($path, $policyDirectory.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isResearchDirectory(string $path): bool
    {
        return $path === 'research-reference' || str_starts_with($path, 'research-reference/');
    }
}
