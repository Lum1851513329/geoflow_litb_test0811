<?php

namespace App\Services\GeoFlow\Keywords;

use App\Models\Admin;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterKeyword;
use App\Models\KeywordNode;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KeywordClusterService
{
    private const ROLES = ['primary', 'secondary', 'question', 'variant'];

    public function __construct(private readonly KeywordClusterSuggestionService $suggestionService) {}

    /** @param array<string,mixed> $suggestion */
    public function confirm(array $suggestion, int $adminId): KeywordCluster
    {
        return DB::transaction(function () use ($suggestion, $adminId): KeywordCluster {
            $admin = Admin::query()->lockForUpdate()->find($adminId)
                ?? throw new DomainException('keyword_cluster.admin_not_found');
            if ($admin->status !== 'active') {
                throw new DomainException('keyword_cluster.admin_inactive');
            }

            $data = $this->normalizeSuggestion($suggestion);
            $keywordIds = array_column($data['keywords'], 'keyword_id');
            $keywords = Keyword::query()
                ->whereIn('id', $keywordIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($keywords->count() !== count($keywordIds)) {
                throw new DomainException('keyword_cluster.keyword_not_found');
            }

            $primary = $keywords->get($data['primary_keyword_id']);
            if ($primary === null) {
                throw new DomainException('keyword_cluster.primary_missing');
            }
            $primaryScopeKey = hash('sha256', implode('|', [
                $primary->id,
                $data['language_code'],
                $data['market_scope'],
            ]));

            $node = KeywordNode::query()->lockForUpdate()->find($data['taxonomy_node_id'])
                ?? throw new DomainException('keyword_cluster.taxonomy_node_not_found');
            $this->assertBoundaries($data, $keywords->all(), $node);
            $this->assertRoleSemantics($data, $keywords->all(), $primary);
            $this->assertSuggestionIntegrity($data, $keywords->all());

            $existing = KeywordCluster::query()
                ->where('slug', $data['slug'])
                ->where('language_code', $data['language_code'])
                ->where('market_scope', $data['market_scope'])
                ->lockForUpdate()
                ->first();
            if ($existing?->confirmed_at !== null) {
                throw new DomainException('keyword_cluster.confirmed_cluster_protected');
            }
            if ($existing !== null) {
                throw new DomainException('keyword_cluster.slug_conflict');
            }

            $primaryConflict = KeywordCluster::query()
                ->where('primary_keyword_id', $primary->id)
                ->where('status', 'active')
                ->where('language_code', $data['language_code'])
                ->where('market_scope', $data['market_scope'])
                ->where('market_code', $data['market_code'])
                ->lockForUpdate()
                ->exists();
            if ($primaryConflict) {
                throw new DomainException('keyword_cluster.primary_active_conflict');
            }

            try {
                $cluster = KeywordCluster::query()->create([
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'taxonomy_node_id' => $node->id,
                    'primary_keyword_id' => $primary->id,
                    'primary_scope_key' => $primaryScopeKey,
                    'language_code' => $data['language_code'],
                    'market_code' => $data['market_code'],
                    'market_scope' => $data['market_scope'],
                    'search_intent' => $data['search_intent'],
                    'content_type' => $data['content_type'],
                    'status' => 'active',
                    'confirmed_by' => $admin->id,
                    'confirmed_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($this->isPrimaryScopeConflict($exception)) {
                    throw new DomainException('keyword_cluster.primary_active_conflict', 0, $exception);
                }

                throw $exception;
            }

            foreach ($data['keywords'] as $index => $membership) {
                KeywordClusterKeyword::query()->create([
                    'cluster_id' => $cluster->id,
                    'keyword_id' => $membership['keyword_id'],
                    'role' => $membership['role'],
                    'weight' => $membership['weight'],
                    'sort_order' => $membership['sort_order'] ?? $index,
                ]);
            }

            return $cluster->refresh();
        });
    }

    /** @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    private function normalizeSuggestion(array $suggestion): array
    {
        foreach (['name', 'slug', 'taxonomy_node_id', 'primary_keyword_id', 'language_code', 'market_scope', 'search_intent', 'brand_relation', 'keywords'] as $field) {
            if (! array_key_exists($field, $suggestion)) {
                throw new DomainException("keyword_cluster.{$field}_required");
            }
        }
        if (! is_array($suggestion['keywords']) || $suggestion['keywords'] === []) {
            throw new DomainException('keyword_cluster.keywords_required');
        }

        $memberships = [];
        foreach ($suggestion['keywords'] as $index => $membership) {
            if (! is_array($membership) || ! isset($membership['keyword_id'], $membership['role'])) {
                throw new DomainException('keyword_cluster.membership_invalid');
            }
            $keywordId = (int) $membership['keyword_id'];
            $role = (string) $membership['role'];
            if ($keywordId <= 0 || ! in_array($role, self::ROLES, true)) {
                throw new DomainException('keyword_cluster.membership_invalid');
            }
            if (isset($memberships[$keywordId])) {
                throw new DomainException('keyword_cluster.keyword_duplicate');
            }
            $memberships[$keywordId] = [
                'keyword_id' => $keywordId,
                'role' => $role,
                'weight' => isset($membership['weight']) ? (float) $membership['weight'] : null,
                'sort_order' => isset($membership['sort_order']) ? (int) $membership['sort_order'] : $index,
            ];
        }

        $primaryIds = array_column(array_filter($memberships, static fn (array $membership): bool => $membership['role'] === 'primary'), 'keyword_id');
        if ($primaryIds !== [(int) $suggestion['primary_keyword_id']]) {
            throw new DomainException('keyword_cluster.primary_invalid');
        }

        $name = trim((string) $suggestion['name']);
        $slug = Str::slug((string) $suggestion['slug']);
        if ($name === '' || $slug === '') {
            throw new DomainException('keyword_cluster.identity_invalid');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'taxonomy_node_id' => (int) $suggestion['taxonomy_node_id'],
            'primary_keyword_id' => (int) $suggestion['primary_keyword_id'],
            'language_code' => (string) $suggestion['language_code'],
            'market_code' => isset($suggestion['market_code']) ? (string) $suggestion['market_code'] : null,
            'market_scope' => (string) $suggestion['market_scope'],
            'search_intent' => (string) $suggestion['search_intent'],
            'brand_relation' => (string) $suggestion['brand_relation'],
            'content_type' => isset($suggestion['content_type']) ? (string) $suggestion['content_type'] : null,
            'keywords' => array_values($memberships),
        ];
    }

    /** @param array<string,mixed> $data
     * @param  array<int,Keyword>  $keywords
     */
    private function assertBoundaries(array $data, array $keywords, KeywordNode $node): void
    {
        $compatibleIntents = config(
            "keyword_intelligence.topic_clustering.compatible_intents.{$data['search_intent']}",
            [$data['search_intent']],
        );
        $compatibleIntents = is_array($compatibleIntents) ? $compatibleIntents : [$data['search_intent']];

        foreach ($keywords as $keyword) {
            if (! DB::table('keyword_node_keyword')->where('node_id', $node->id)->where('keyword_id', $keyword->id)->exists()) {
                throw new DomainException('keyword_cluster.taxonomy_boundary');
            }
            if ($keyword->language_code !== $data['language_code']) {
                throw new DomainException('keyword_cluster.language_boundary');
            }
            if ($keyword->market_code !== $data['market_code'] || $keyword->market_scope !== $data['market_scope']) {
                throw new DomainException('keyword_cluster.market_boundary');
            }
            if ($keyword->brand_relation !== $data['brand_relation']) {
                throw new DomainException('keyword_cluster.brand_boundary');
            }
            if (! in_array($keyword->search_intent, $compatibleIntents, true)) {
                throw new DomainException('keyword_cluster.intent_boundary');
            }
            if ($keyword->status !== 'active') {
                throw new DomainException('keyword_cluster.keyword_inactive');
            }
        }
    }

    /** @param array<string,mixed> $data
     * @param  array<int,Keyword>  $keywords
     */
    private function assertRoleSemantics(array $data, array $keywords, Keyword $primary): void
    {
        foreach ($data['keywords'] as $membership) {
            $keyword = $keywords[$membership['keyword_id']];
            $submittedRole = $membership['role'];
            $expectedRole = $this->suggestionService->roleFor($keyword, $primary);
            if ($submittedRole === 'question' && $keyword->keyword_type !== 'question') {
                throw new DomainException('keyword_cluster.question_role_invalid');
            }
            if ($submittedRole === 'variant' && $expectedRole !== 'variant') {
                throw new DomainException('keyword_cluster.variant_role_invalid');
            }
            if ($submittedRole !== $expectedRole) {
                throw new DomainException('keyword_cluster.role_invalid');
            }
        }
    }

    /** @param array<string,mixed> $data
     * @param  array<int,Keyword>  $keywords
     */
    private function assertSuggestionIntegrity(array $data, array $keywords): void
    {
        $rebuilt = collect($this->suggestionService->suggest($keywords))->first(
            static fn (array $candidate): bool => $candidate['primary_keyword_id'] === $data['primary_keyword_id'],
        );
        if ($rebuilt === null) {
            throw new DomainException('keyword_cluster.stem_boundary');
        }

        $submittedIds = array_column($data['keywords'], 'keyword_id');
        $rebuiltIds = array_column($rebuilt['keywords'], 'keyword_id');
        sort($submittedIds);
        sort($rebuiltIds);
        if ($submittedIds !== $rebuiltIds) {
            throw new DomainException('keyword_cluster.stem_boundary');
        }

        foreach (['taxonomy_node_id', 'language_code', 'market_code', 'market_scope', 'brand_relation', 'search_intent'] as $field) {
            if ($data[$field] !== $rebuilt[$field]) {
                throw new DomainException('keyword_cluster.suggestion_tampered');
            }
        }

        $submittedRoles = collect($data['keywords'])->pluck('role', 'keyword_id')->all();
        $rebuiltRoles = collect($rebuilt['keywords'])->pluck('role', 'keyword_id')->all();
        if ($submittedRoles !== $rebuiltRoles) {
            throw new DomainException('keyword_cluster.role_invalid');
        }
    }

    private function isPrimaryScopeConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'primary_scope_key')
            || str_contains($message, 'keyword_clusters_primary_scope_unique');
    }
}
