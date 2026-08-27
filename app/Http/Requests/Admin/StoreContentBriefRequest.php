<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentBrief;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class StoreContentBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin
            && Gate::forUser($admin)->allows('create', ContentBrief::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'knowledge_mode' => ['nullable', 'in:legacy_manual,brand_auto'],
            'knowledge_brand_id' => ['nullable', 'integer', 'min:1'],
            'knowledge_site_id' => ['nullable', 'integer', 'min:1'],
            'knowledge_directory_id' => ['nullable', 'integer', 'min:1'],
            'external_evidence_policy' => ['nullable', Rule::in(['off', 'on_insufficient'])],
            'external_general_domains' => ['nullable', 'array', 'max:20'],
            'external_general_domains.*' => ['string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'topic' => ['required', 'string', 'max:300'],
            'market_code' => ['sometimes', 'required_without:prompt_preset_id', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
            'language_code' => ['sometimes', 'required_without:prompt_preset_id', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/'],
            'audience' => ['sometimes', 'required_without:prompt_preset_id', 'string', 'max:5000'],
            'core_question' => ['required', 'string', 'max:5000'],
            'business_goal' => ['sometimes', 'required_without:prompt_preset_id', 'string', 'max:5000'],
            'content_type' => ['sometimes', 'required_without:prompt_preset_id', 'string', 'max:50'],
            'planned_publish_at' => ['nullable', 'date'],
            'keyword_cluster_id' => ['nullable', 'integer', 'exists:keyword_clusters,id'],
            'primary_keyword_id' => ['nullable', 'integer', 'exists:keywords,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'target_url' => ['nullable', 'url:http,https', 'max:1000'],
            'required_mentions' => ['nullable', 'array', 'max:100'],
            'required_mentions.*' => ['string', 'max:500'],
            'prohibited_information' => ['nullable', 'array', 'max:100'],
            'prohibited_information.*' => ['string', 'max:1000'],
            'campaign_starts_at' => ['nullable', 'date'],
            'campaign_ends_at' => ['nullable', 'date', 'after_or_equal:campaign_starts_at'],
            'reference_urls' => ['nullable', 'array', 'max:100'],
            'reference_urls.*' => ['url:http,https', 'max:1000'],
            'cta_strength' => ['nullable', Rule::in(['none', 'soft', 'medium', 'strong'])],
            'prompt_preset_id' => ['nullable', 'integer', 'exists:prompt_presets,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function briefData(): array
    {
        $data = $this->safe()->except(['campaign_starts_at', 'campaign_ends_at']);
        $startsAt = $this->validated('campaign_starts_at');
        $endsAt = $this->validated('campaign_ends_at');
        $data['campaign_window'] = $startsAt !== null || $endsAt !== null
            ? ['starts_at' => $startsAt, 'ends_at' => $endsAt]
            : null;

        return array_merge($data, app(GenerationKnowledgeScopeResolver::class)->normalize($data));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                app(GenerationKnowledgeScopeResolver::class)->normalize($this->all());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('prompt_preset_id')) {
            foreach (['market_code', 'language_code', 'audience', 'business_goal', 'content_type'] as $field) {
                if (! $this->filled($field)) {
                    $this->request->remove($field);
                }
            }
        }
        $this->merge([
            'required_mentions' => $this->lines($this->input('required_mentions')),
            'prohibited_information' => $this->lines($this->input('prohibited_information')),
            'reference_urls' => $this->lines($this->input('reference_urls')),
            'external_general_domains' => array_values(array_unique(array_map(
                static fn (mixed $domain): string => strtolower(trim((string) $domain, " .\t\n\r\0\x0B")),
                $this->lines($this->input('external_general_domains')) ?? [],
            ))),
            'external_evidence_policy' => (string) $this->input('external_evidence_policy', 'off'),
        ]);

        // Only normalize market_code/language_code when actually submitted: the quick-create
        // (prompt_preset_id) path omits them entirely, and forcing them to a coerced empty
        // string here would make the `sometimes` rule engage and fail regex validation even
        // though these fields are optional on that path.
        if ($this->has('market_code')) {
            $this->merge(['market_code' => strtoupper(trim((string) $this->input('market_code')))]);
        }
        if ($this->has('language_code')) {
            $this->merge(['language_code' => $this->canonicalLanguageTag((string) $this->input('language_code'))]);
        }
    }

    /** @return list<mixed>|null */
    private function lines(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return array_values(array_filter($value, static fn (mixed $item): bool => ! is_string($item) || trim($item) !== ''));
        }

        return array_values(array_filter(
            preg_split('/\R/u', (string) $value) ?: [],
            static fn (string $item): bool => trim($item) !== '',
        ));
    }

    private function canonicalLanguageTag(string $value): string
    {
        $parts = explode('-', str_replace('_', '-', trim($value)));
        foreach ($parts as $index => &$part) {
            if ($index === 0) {
                $part = strtolower($part);
            } elseif (strlen($part) === 4 && ctype_alpha($part)) {
                $part = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && ctype_alpha($part)) || (strlen($part) === 3 && ctype_digit($part))) {
                $part = strtoupper($part);
            } else {
                $part = strtolower($part);
            }
        }
        unset($part);

        return implode('-', $parts);
    }
}
