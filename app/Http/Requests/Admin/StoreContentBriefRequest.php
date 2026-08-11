<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentBrief;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
            'topic' => ['required', 'string', 'max:300'],
            'market_code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]+$/'],
            'language_code' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/'],
            'audience' => ['required', 'string', 'max:5000'],
            'core_question' => ['required', 'string', 'max:5000'],
            'business_goal' => ['required', 'string', 'max:5000'],
            'content_type' => ['required', 'string', 'max:50'],
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

        return $data;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'market_code' => strtoupper(trim((string) $this->input('market_code'))),
            'language_code' => $this->canonicalLanguageTag((string) $this->input('language_code')),
            'required_mentions' => $this->lines($this->input('required_mentions')),
            'prohibited_information' => $this->lines($this->input('prohibited_information')),
            'reference_urls' => $this->lines($this->input('reference_urls')),
        ]);
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
