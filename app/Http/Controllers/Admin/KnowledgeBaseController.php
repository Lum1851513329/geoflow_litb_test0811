<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGovernedKnowledgeRequest;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\Task;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 知识库管理控制器。
 */
class KnowledgeBaseController extends Controller
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    private const MAX_DOCX_XML_BYTES = 16 * 1024 * 1024;

    private const MAX_DOCX_COMPRESSION_RATIO = 100;

    public function __construct(private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator) {}

    /**
     * 列表页。
     */
    public function index(Request $request): View
    {
        $filters = $this->knowledgeFilters($request);
        $directoryIds = $this->filteredDirectoryIds($filters);

        return view('admin.knowledge-bases.index', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBases' => $this->loadKnowledgeBases($filters, $directoryIds),
            'stats' => $this->loadStats($filters, $directoryIds),
            'hasDefaultEmbeddingModel' => $this->hasDefaultEmbeddingModel(),
            'filters' => $filters,
        ] + $this->governanceNavigation($filters));
    }

    /**
     * 创建表单页。
     */
    public function create(Request $request): View
    {
        $brandId = $request->integer('brand_id') ?: null;

        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'knowledgeBaseId' => 0,
            'knowledgeForm' => $this->emptyForm($brandId),
        ] + $this->governanceFormOptions());
    }

    /**
     * 知识库详情页，展示切块与向量状态。
     */
    public function detail(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()
            ->with([
                'brand:id,key,name',
                'site:id,brand_id,key,canonical_host,role',
                'primaryDirectory:id,brand_id,parent_id,key,path,name',
                'duplicateAuditRows.canonicalDocument:id,name,version_label,lifecycle_status',
                'canonicalDuplicateAuditRows.duplicateDocument:id,name,version_label,lifecycle_status',
            ])
            ->withCount(['keywordNodes', 'keywordClusters'])
            ->whereKey($knowledgeBaseId)
            ->firstOrFail();

        return view('admin.knowledge-bases.detail', [
            'pageTitle' => __('admin.knowledge_detail.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'knowledgeBase' => $knowledgeBase,
            'relatedTasks' => $this->loadRelatedTasks($knowledgeBaseId),
            'chunkStats' => $this->loadChunkStats($knowledgeBaseId),
            'chunkPreviewRows' => $this->loadChunkPreviewRows($knowledgeBaseId),
        ]);
    }

    /**
     * 详情页更新知识库内容并同步 chunk。
     */
    public function updateFromDetail(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()
            ->with(['brand:id', 'site:id,brand_id', 'primaryDirectory:id,brand_id'])
            ->whereKey($knowledgeBaseId)
            ->firstOrFail();
        $this->assertGovernedOwnership($knowledgeBase);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'file_type' => ['required', 'in:markdown,word,text'],
        ], [
            'name.required' => __('admin.knowledge_bases.error.name_required'),
            'content.required' => __('admin.knowledge_bases.error.content_required'),
        ]);

        $content = trim((string) $payload['content']);
        $this->assertKnowledgeContentSize($content);
        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'normalized_content_hash' => null,
        ]);

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            'admin.knowledge-bases.detail',
            ['knowledgeBaseId' => $knowledgeBaseId],
        );
    }

    /**
     * 上传知识文档并写入知识库。
     */
    public function uploadFile(StoreGovernedKnowledgeRequest $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'upload_error');
    }

    /**
     * 创建知识库并同步 chunks。
     */
    public function store(StoreGovernedKnowledgeRequest $request): RedirectResponse
    {
        return $this->createKnowledgeBaseFromRequest($request, 'create_error');
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $knowledgeBaseId): View|RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()
            ->with(['brand:id,key,name', 'site:id,brand_id,key,canonical_host', 'primaryDirectory:id,brand_id,key,path,name'])
            ->whereKey($knowledgeBaseId)
            ->firstOrFail();

        return view('admin.knowledge-bases.form', [
            'pageTitle' => __('admin.knowledge_bases.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'knowledgeBaseId' => (int) $knowledgeBase->id,
            'knowledgeForm' => [
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'content' => (string) ($knowledgeBase->content ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'source_name' => (string) ($knowledgeBase->source_name ?? ''),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'source_type' => (string) ($knowledgeBase->source_type ?? 'document'),
                'business_line' => (string) ($knowledgeBase->business_line ?? ''),
                'effective_date' => $knowledgeBase->effective_date?->toDateString() ?? '',
                'risk_level' => (string) ($knowledgeBase->risk_level ?? 'medium'),
                'review_status' => (string) ($knowledgeBase->review_status ?? 'unreviewed'),
                'brand_id' => $knowledgeBase->brand_id,
                'site_id' => $knowledgeBase->site_id,
                'primary_directory_id' => $knowledgeBase->primary_directory_id,
                'canonical_document_key' => (string) ($knowledgeBase->canonical_document_key ?? ''),
                'language_code' => (string) ($knowledgeBase->language_code ?? 'en'),
                'authority_level' => (string) ($knowledgeBase->authority_level ?? 'third_party'),
                'retrieved_at' => $knowledgeBase->retrieved_at?->toDateString() ?? '',
                'review_due_at' => $knowledgeBase->review_due_at?->toDateString() ?? '',
                'version_label' => (string) ($knowledgeBase->version_label ?? ''),
                'lifecycle_status' => (string) ($knowledgeBase->lifecycle_status ?? 'active'),
                'generation_eligible' => (bool) ($knowledgeBase->generation_eligible ?? false),
                'citation_required' => (bool) ($knowledgeBase->citation_required ?? true),
            ],
            'chunkCount' => (int) $knowledgeBase->chunks()->count(),
        ] + $this->governanceFormOptions());
    }

    /**
     * 更新知识库并重建 chunks。
     */
    public function update(StoreGovernedKnowledgeRequest $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $payload = $request->validated();
        $content = trim((string) $payload['content']);

        $knowledgeBase->update([
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'content' => $content,
            'file_type' => (string) $payload['file_type'],
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
            'normalized_content_hash' => null,
        ] + $this->knowledgeMetadataPayload($payload, $knowledgeBase));

        return $this->redirectAfterChunkSync(
            $knowledgeBase,
            'admin.knowledge-bases.index',
            [],
        );
    }

    /**
     * 删除知识库（存在任务引用时阻止）。
     */
    public function destroy(int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();

        $taskCount = $this->knowledgeBaseTaskCount($knowledgeBaseId);
        if ($taskCount > 0) {
            return back()->withErrors(__('admin.knowledge_bases.error.in_use', ['count' => $taskCount]));
        }

        $filePath = (string) ($knowledgeBase->file_path ?? '');
        $knowledgeBase->delete();
        $this->cleanupKnowledgeFile($filePath);

        return redirect()->route('admin.knowledge-bases.index')->with('message', __('admin.knowledge_bases.message.delete_success'));
    }

    public function refreshChunks(Request $request, int $knowledgeBaseId): RedirectResponse
    {
        $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
        $content = trim((string) ($knowledgeBase->content ?? ''));
        $redirect = $this->knowledgeChunkRefreshRedirect($request);

        if ($content === '') {
            return $redirect
                ->withErrors(__('admin.knowledge_bases.error.content_required'));
        }

        $this->chunkSyncCoordinator->request(
            (int) $knowledgeBase->id,
            requireRealEmbedding: true,
            force: true,
        );

        return $redirect->with('message', __('admin.knowledge_bases.message.chunks_refresh_queued'));
    }

    private function knowledgeChunkRefreshRedirect(Request $request): RedirectResponse
    {
        $redirectTo = trim((string) $request->input('redirect_to', ''));
        $allowedPrefix = route('admin.knowledge-bases.index', [], false);

        if ($redirectTo !== '' && str_starts_with($redirectTo, $allowedPrefix) && ! str_starts_with($redirectTo, '//')) {
            return redirect()->to($redirectTo);
        }

        return redirect()->route('admin.knowledge-bases.index');
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @param  list<int>  $directoryIds
     * @return array<int, array<string,mixed>>
     */
    private function loadKnowledgeBases(array $filters, array $directoryIds): array
    {
        $query = KnowledgeBase::query()
            ->select([
                'id',
                'name',
                'description',
                'file_type',
                'word_count',
                'usage_count',
                'chunk_sync_status',
                'chunk_sync_error',
                'chunk_synced_at',
                'brand_id',
                'site_id',
                'primary_directory_id',
                'language_code',
                'authority_level',
                'risk_level',
                'review_status',
                'lifecycle_status',
                'generation_eligible',
                'citation_required',
                'version_label',
                'canonical_document_key',
                'source_name',
                'source_url',
                'retrieved_at',
                'review_due_at',
                'created_at',
                'updated_at',
            ])
            ->selectSub(function ($query): void {
                $query->from('knowledge_bases as versions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('versions.brand_id', 'knowledge_bases.brand_id')
                    ->whereColumn('versions.site_id', 'knowledge_bases.site_id')
                    ->whereColumn('versions.canonical_document_key', 'knowledge_bases.canonical_document_key');
            }, 'version_count')
            ->with([
                'brand:id,key,name',
                'site:id,brand_id,key,canonical_host',
                'primaryDirectory:id,brand_id,parent_id,key,path,name',
                'duplicateAuditRows:id,duplicate_document_id,canonical_document_id,duplicate_kind',
                'canonicalDuplicateAuditRows:id,duplicate_document_id,canonical_document_id,duplicate_kind',
            ])
            ->withCount('chunks as chunk_count')
            ->withCount([
                'chunks as vectorized_chunk_count' => fn ($query) => $query
                    ->whereNotNull('embedding_model_id')
                    ->where('embedding_dimensions', '>', 0),
            ]);

        $this->applyKnowledgeFilters($query, $filters, $directoryIds);
        $query->orderByDesc('created_at')->orderByDesc('id');

        return $query->get()->map(static function (KnowledgeBase $knowledgeBase): array {
            $duplicateKinds = $knowledgeBase->duplicateAuditRows
                ->concat($knowledgeBase->canonicalDuplicateAuditRows)
                ->pluck('duplicate_kind')
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                'id' => (int) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
                'description' => (string) ($knowledgeBase->description ?? ''),
                'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
                'word_count' => (int) ($knowledgeBase->word_count ?? 0),
                'usage_count' => (int) ($knowledgeBase->usage_count ?? 0),
                'chunk_count' => (int) ($knowledgeBase->chunk_count ?? 0),
                'vectorized_chunk_count' => (int) ($knowledgeBase->vectorized_chunk_count ?? 0),
                'chunk_sync_status' => (string) ($knowledgeBase->chunk_sync_status ?? 'idle'),
                'chunk_sync_error' => trim((string) ($knowledgeBase->chunk_sync_error ?? '')),
                'chunk_synced_at' => $knowledgeBase->chunk_synced_at?->format('Y-m-d H:i:s'),
                'brand_name' => (string) ($knowledgeBase->brand?->name ?? ''),
                'brand_key' => (string) ($knowledgeBase->brand?->key ?? ''),
                'site_key' => (string) ($knowledgeBase->site?->key ?? ''),
                'site_host' => (string) ($knowledgeBase->site?->canonical_host ?? ''),
                'directory_name' => (string) ($knowledgeBase->primaryDirectory?->name ?? ''),
                'directory_path' => (string) ($knowledgeBase->primaryDirectory?->path ?? ''),
                'language_code' => (string) ($knowledgeBase->language_code ?? ''),
                'authority_level' => (string) ($knowledgeBase->authority_level ?? ''),
                'risk_level' => (string) ($knowledgeBase->risk_level ?? ''),
                'review_status' => (string) ($knowledgeBase->review_status ?? ''),
                'lifecycle_status' => (string) ($knowledgeBase->lifecycle_status ?? ''),
                'generation_eligible' => (bool) ($knowledgeBase->generation_eligible ?? false),
                'citation_required' => (bool) ($knowledgeBase->citation_required ?? true),
                'version_label' => (string) ($knowledgeBase->version_label ?? ''),
                'version_count' => (int) ($knowledgeBase->version_count ?? 0),
                'duplicate_kinds' => $duplicateKinds,
                'source_name' => (string) ($knowledgeBase->source_name ?? ''),
                'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                'retrieved_at' => $knowledgeBase->retrieved_at?->format('Y-m-d'),
                'review_due_at' => $knowledgeBase->review_due_at?->format('Y-m-d'),
                'created_at' => $knowledgeBase->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * 判断是否存在可用的 embedding 模型，用于知识库列表按钮引导。
     */
    private function hasDefaultEmbeddingModel(): bool
    {
        return AiModel::query()
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
            ->exists();
    }

    /**
     * @return array{total_knowledge:int,total_words:int,markdown_count:int,word_count:int}
     */
    private function loadStats(array $filters, array $directoryIds): array
    {
        $query = KnowledgeBase::query();
        $this->applyKnowledgeFilters($query, $filters, $directoryIds);
        $stats = $query->selectRaw(<<<'SQL'
COUNT(*) AS total_knowledge,
COALESCE(SUM(word_count), 0) AS total_words,
COALESCE(SUM(CASE WHEN file_type = 'markdown' THEN 1 ELSE 0 END), 0) AS markdown_count,
COALESCE(SUM(CASE WHEN file_type = 'word' THEN 1 ELSE 0 END), 0) AS word_count
SQL)->first();

        return [
            'total_knowledge' => (int) ($stats?->total_knowledge ?? 0),
            'total_words' => (int) ($stats?->total_words ?? 0),
            'markdown_count' => (int) ($stats?->markdown_count ?? 0),
            'word_count' => (int) ($stats?->word_count ?? 0),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function knowledgeFilters(Request $request): array
    {
        return [
            'brand_id' => $request->integer('brand_id') ?: null,
            'site_id' => $request->integer('site_id') ?: null,
            'directory_id' => $request->integer('directory_id') ?: null,
            'language_code' => trim((string) $request->query('language_code')) ?: null,
            'authority_level' => trim((string) $request->query('authority_level')) ?: null,
            'risk_level' => trim((string) $request->query('risk_level')) ?: null,
            'review_status' => trim((string) $request->query('review_status')) ?: null,
            'lifecycle_status' => trim((string) $request->query('lifecycle_status')) ?: null,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @return list<int>
     */
    private function filteredDirectoryIds(array $filters): array
    {
        $directoryId = (int) ($filters['directory_id'] ?? 0);
        if ($directoryId < 1) {
            return [];
        }

        $directory = KnowledgeDirectory::query()->find($directoryId);
        if (! $directory instanceof KnowledgeDirectory) {
            return [-1];
        }
        $brandId = (int) ($filters['brand_id'] ?? 0);
        if ($brandId > 0 && (int) $directory->brand_id !== $brandId) {
            return [-1];
        }

        return KnowledgeDirectory::query()
            ->where('brand_id', $directory->brand_id)
            ->where(function ($query) use ($directory): void {
                $query->whereKey($directory->id)
                    ->orWhere('path', 'like', $this->descendantDirectoryPattern((string) $directory->path));
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @param  list<int>  $directoryIds
     */
    private function applyKnowledgeFilters($query, array $filters, array $directoryIds): void
    {
        foreach (['brand_id', 'site_id'] as $field) {
            if ((int) ($filters[$field] ?? 0) > 0) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        if ((int) ($filters['directory_id'] ?? 0) > 0) {
            $query->whereIn('primary_directory_id', $directoryIds ?: [-1]);
        }
        foreach (['language_code', 'authority_level', 'risk_level', 'review_status', 'lifecycle_status'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, (string) $filters[$field]);
            }
        }
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @return array<string, mixed>
     */
    private function governanceNavigation(array $filters): array
    {
        $brandId = (int) ($filters['brand_id'] ?? 0);
        $directories = $brandId > 0
            ? KnowledgeDirectory::query()->where('brand_id', $brandId)->orderBy('path')->get()
            : collect();

        return [
            'brandOptions' => KnowledgeBrand::query()->orderBy('name')->get(['id', 'key', 'name']),
            'siteOptions' => KnowledgeSite::query()
                ->when($brandId > 0, fn ($query) => $query->where('brand_id', $brandId))
                ->orderBy('canonical_host')
                ->get(['id', 'brand_id', 'key', 'canonical_host']),
            'directoryRows' => $this->flattenDirectoryRows($directories),
            'languageOptions' => KnowledgeBase::query()
                ->whereNotNull('language_code')
                ->where('language_code', '<>', '')
                ->distinct()
                ->orderBy('language_code')
                ->pluck('language_code')
                ->prepend('en')
                ->unique()
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function governanceFormOptions(): array
    {
        return [
            'brandOptions' => KnowledgeBrand::query()->orderBy('name')->get(['id', 'key', 'name']),
            'siteOptions' => KnowledgeSite::query()->orderBy('canonical_host')->get(['id', 'brand_id', 'key', 'canonical_host']),
            'directoryOptions' => KnowledgeDirectory::query()->orderBy('path')->get(['id', 'brand_id', 'path', 'name']),
        ];
    }

    /** @param  Collection<int, KnowledgeDirectory>  $directories */
    private function flattenDirectoryRows(Collection $directories): array
    {
        $knownIds = $directories->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $children = $directories->groupBy(static fn (KnowledgeDirectory $directory): int => (int) ($directory->parent_id ?? 0));
        $roots = $directories->filter(static fn (KnowledgeDirectory $directory): bool => $directory->parent_id === null
            || ! in_array((int) $directory->parent_id, $knownIds, true));
        $rows = [];
        $append = function (KnowledgeDirectory $directory, int $depth) use (&$append, &$rows, $children): void {
            $rows[] = ['directory' => $directory, 'depth' => $depth];
            foreach ($children->get((int) $directory->id, collect()) as $child) {
                $append($child, $depth + 1);
            }
        };
        foreach ($roots as $root) {
            $append($root, 0);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function emptyForm(?int $requestedBrandId = null): array
    {
        $brandId = $requestedBrandId;
        if ($brandId === null && KnowledgeBrand::query()->count() === 1) {
            $brandId = (int) KnowledgeBrand::query()->value('id');
        }
        $siteId = $brandId ? KnowledgeSite::query()->where('brand_id', $brandId)->orderBy('id')->value('id') : null;
        $directoryId = $brandId ? KnowledgeDirectory::query()->where('brand_id', $brandId)->orderBy('path')->value('id') : null;

        return [
            'name' => '',
            'description' => '',
            'content' => '',
            'file_type' => 'markdown',
            'source_name' => '',
            'source_url' => '',
            'source_type' => 'official_web_page',
            'business_line' => '',
            'effective_date' => '',
            'risk_level' => 'medium',
            'review_status' => 'unreviewed',
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'primary_directory_id' => $directoryId,
            'canonical_document_key' => '',
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'retrieved_at' => '',
            'review_due_at' => '',
            'version_label' => '',
            'lifecycle_status' => 'active',
            'generation_eligible' => false,
            'citation_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string, mixed>
     */
    private function knowledgeMetadataPayload(
        array $payload,
        ?KnowledgeBase $existing = null,
        string $sourceFilePath = '',
        ?string $sourceFileHash = null,
    ): array {
        $canonicalKey = trim((string) ($payload['canonical_document_key'] ?? ''));
        if ($canonicalKey === '') {
            $canonicalKey = trim((string) ($existing?->canonical_document_key ?? '')) ?: 'manual:'.Str::uuid();
        }

        return [
            'brand_id' => (int) $payload['brand_id'],
            'site_id' => (int) $payload['site_id'],
            'primary_directory_id' => (int) $payload['primary_directory_id'],
            'asset_key' => $existing?->asset_key ?: 'knowledge:admin:'.Str::uuid(),
            'asset_provenance' => [
                'ingestion_method' => $sourceFilePath === '' ? 'admin_manual' : 'admin_upload',
                'source_name' => trim((string) $payload['source_name']),
                'source_url' => trim((string) ($payload['source_url'] ?? '')),
            ],
            'canonical_document_key' => $canonicalKey,
            'language_code' => (string) $payload['language_code'],
            'authority_level' => (string) $payload['authority_level'],
            'source_name' => trim((string) $payload['source_name']),
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'source_type' => (string) $payload['source_type'],
            'business_line' => trim((string) ($payload['business_line'] ?? '')),
            'effective_date' => filled($payload['effective_date'] ?? null) ? (string) $payload['effective_date'] : null,
            'retrieved_at' => filled($payload['retrieved_at'] ?? null) ? (string) $payload['retrieved_at'] : null,
            'review_due_at' => filled($payload['review_due_at'] ?? null) ? (string) $payload['review_due_at'] : null,
            'risk_level' => (string) $payload['risk_level'],
            'review_status' => (string) $payload['review_status'],
            'version_label' => trim((string) ($payload['version_label'] ?? '')) ?: (string) ($existing?->version_label ?? 'manual'),
            'lifecycle_status' => (string) $payload['lifecycle_status'],
            'generation_eligible' => (bool) ($payload['generation_eligible'] ?? false),
            'citation_required' => array_key_exists('citation_required', $payload)
                ? (bool) $payload['citation_required']
                : (bool) ($existing?->citation_required ?? true),
            'source_file_path' => $sourceFilePath !== '' ? $sourceFilePath : $existing?->source_file_path,
            'source_file_hash' => $sourceFileHash ?? $existing?->source_file_hash,
        ];
    }

    private function createKnowledgeBaseFromRequest(StoreGovernedKnowledgeRequest $request, string $errorMessageKey): RedirectResponse
    {
        $payload = $request->validated();
        $storedPaths = [];
        $knowledgeBase = null;

        try {
            $manualContent = $this->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
            $uploadedFiles = $this->uploadedKnowledgeFiles($request);
            $this->assertKnowledgeContentSize($manualContent);
            $this->assertUploadedKnowledgeTotalSize($uploadedFiles);

            if (count($uploadedFiles) > 10) {
                throw ValidationException::withMessages([
                    'knowledge_files' => __('admin.knowledge_bases.error.files_limit'),
                ]);
            }

            if ($manualContent === '' && $uploadedFiles === []) {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                    'knowledge_files' => __('admin.knowledge_bases.error.file_required'),
                ]);
            }

            $parsedFiles = $this->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths);
            $content = $this->mergeKnowledgeSources($manualContent, $parsedFiles);
            $this->assertKnowledgeContentSize($content);
            if ($content === '') {
                throw ValidationException::withMessages([
                    'content' => __('admin.knowledge_bases.error.content_required'),
                ]);
            }

            $knowledgeName = trim((string) ($payload['name'] ?? ''));
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeName($uploadedFiles);
            }
            if ($knowledgeName === '') {
                $knowledgeName = $this->inferKnowledgeNameFromContent($manualContent);
            }
            if ($knowledgeName === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.knowledge_bases.error.name_required'),
                ]);
            }

            $fileType = $this->resolveKnowledgeFileType(
                (string) ($payload['file_type'] ?? 'markdown'),
                $manualContent,
                $parsedFiles
            );
            $encodedFilePath = $this->encodeKnowledgeFilePaths($storedPaths);
            $sourceFileHash = $this->sourceFileHash($storedPaths);

            $knowledgeBase = DB::transaction(function () use ($knowledgeName, $payload, $content, $fileType, $encodedFilePath, $sourceFileHash): KnowledgeBase {
                return KnowledgeBase::query()->create([
                    'name' => $knowledgeName,
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'content' => $content,
                    'file_type' => $fileType,
                    'character_count' => mb_strlen($content, 'UTF-8'),
                    'word_count' => mb_strlen(strip_tags($content), 'UTF-8'),
                    'usage_count' => 0,
                    'used_task_count' => 0,
                    'file_path' => $encodedFilePath,
                ] + $this->knowledgeMetadataPayload(
                    $payload,
                    sourceFilePath: $encodedFilePath,
                    sourceFileHash: $sourceFileHash,
                ));
            });

            if (($payload['import_action'] ?? 'save_and_chunk') === 'save') {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->with('message', __('admin.knowledge_bases.message.create_saved'));
            }

            return $this->redirectAfterChunkSync(
                $knowledgeBase,
                'admin.knowledge-bases.index',
                [],
            );
        } catch (ValidationException $exception) {
            $this->cleanupKnowledgeFiles($storedPaths);

            throw $exception;
        } catch (\Throwable $exception) {
            if ($knowledgeBase instanceof KnowledgeBase) {
                return redirect()
                    ->route('admin.knowledge-bases.index')
                    ->withErrors([
                        'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                            'message' => $exception->getMessage(),
                        ]),
                    ]);
            }

            $this->cleanupKnowledgeFiles($storedPaths);

            return back()
                ->withInput($request->except(['knowledge_file', 'knowledge_files']))
                ->withErrors(__('admin.knowledge_bases.message.'.$errorMessageKey, ['message' => $exception->getMessage()]));
        }
    }

    private function descendantDirectoryPattern(string $path): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path).'/%';
    }

    /** @param  list<string>  $storedPaths */
    private function sourceFileHash(array $storedPaths): ?string
    {
        if ($storedPaths === []) {
            return null;
        }

        $hash = hash_init('sha256');
        foreach ($storedPaths as $path) {
            $absolutePath = Storage::disk('local')->path($path);
            $fileHash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
            if (! is_string($fileHash)) {
                return null;
            }
            hash_update($hash, $path."\0".$fileHash."\n");
        }

        return hash_final($hash);
    }

    private function assertGovernedOwnership(KnowledgeBase $knowledgeBase): void
    {
        $brandId = (int) ($knowledgeBase->brand_id ?? 0);
        $siteId = (int) ($knowledgeBase->site_id ?? 0);
        $directoryId = (int) ($knowledgeBase->primary_directory_id ?? 0);
        $isGoverned = $brandId > 0
            && $siteId > 0
            && $directoryId > 0
            && $knowledgeBase->brand !== null
            && $knowledgeBase->site !== null
            && $knowledgeBase->primaryDirectory !== null
            && (int) $knowledgeBase->site->brand_id === $brandId
            && (int) $knowledgeBase->primaryDirectory->brand_id === $brandId;

        if (! $isGoverned) {
            throw ValidationException::withMessages([
                'brand_id' => __('admin.knowledge_bases.error.governed_owner_required'),
            ]);
        }
    }

    /**
     * 保存知识库后提交后台切片任务。
     *
     * @param  array<string, mixed>  $routeParameters
     */
    private function redirectAfterChunkSync(KnowledgeBase $knowledgeBase, string $routeName, array $routeParameters): RedirectResponse
    {
        try {
            $this->chunkSyncCoordinator->request((int) $knowledgeBase->id);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route($routeName, $routeParameters)
                ->withErrors([
                    'chunk_sync' => __('admin.knowledge_bases.message.chunk_sync_deferred', [
                        'message' => $exception->getMessage(),
                    ]),
                ]);
        }

        return redirect()
            ->route($routeName, $routeParameters)
            ->with('message', __('admin.knowledge_bases.message.chunk_sync_queued'));
    }

    /**
     * @return array{chunk_count:int,vectorized_count:int}
     */
    private function loadChunkStats(int $knowledgeBaseId): array
    {
        $chunkCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->count();
        $vectorizedCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();

        return ['chunk_count' => $chunkCount, 'vectorized_count' => $vectorizedCount];
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private function loadRelatedTasks(int $knowledgeBaseId): EloquentCollection
    {
        $taskIds = $this->taskIdsUsingKnowledgeBase($knowledgeBaseId);
        if ($taskIds === []) {
            return Task::query()->whereRaw('1 = 0')->get();
        }

        return Task::query()
            ->select(['id', 'name', 'status', 'updated_at'])
            ->whereIn('id', $taskIds)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    private function knowledgeBaseTaskCount(int $knowledgeBaseId): int
    {
        return count($this->taskIdsUsingKnowledgeBase($knowledgeBaseId));
    }

    /**
     * @return list<int>
     */
    private function taskIdsUsingKnowledgeBase(int $knowledgeBaseId): array
    {
        $taskIds = Task::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (Schema::hasTable('task_knowledge_bases')) {
            $taskIds = array_merge(
                $taskIds,
                DB::table('task_knowledge_bases')
                    ->where('knowledge_base_id', $knowledgeBaseId)
                    ->pluck('task_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all()
            );
        }

        return collect($taskIds)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadChunkPreviewRows(int $knowledgeBaseId): Collection
    {
        return KnowledgeChunk::query()
            ->select([
                'chunk_index',
                'content',
                'chunk_title',
                'section_path',
                'chunk_strategy',
                'token_count',
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
            ])
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->limit(20)
            ->get()
            ->map(static function (KnowledgeChunk $chunk): array {
                $preview = mb_substr(trim((string) $chunk->content), 0, 160, 'UTF-8');

                return [
                    'chunk_index' => (int) $chunk->chunk_index,
                    'content_length' => mb_strlen((string) $chunk->content, 'UTF-8'),
                    'token_count' => (int) ($chunk->token_count ?? 0),
                    'embedding_model_id' => $chunk->embedding_model_id !== null ? (int) $chunk->embedding_model_id : null,
                    'embedding_dimensions' => (int) ($chunk->embedding_dimensions ?? 0),
                    'embedding_provider' => (string) ($chunk->embedding_provider ?? ''),
                    'chunk_title' => (string) ($chunk->chunk_title ?? ''),
                    'section_path' => (string) ($chunk->section_path ?? ''),
                    'chunk_strategy' => (string) ($chunk->chunk_strategy ?? 'structured_rule'),
                    'content_preview' => $preview,
                ];
            });
    }

    /**
     * 保存上传知识文件到本地路径。
     */
    private function storeUploadedKnowledgeFile(UploadedFile $file): string
    {
        $relativeDirectory = 'uploads/knowledge';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function uploadedKnowledgeFiles(Request $request): array
    {
        $files = [];

        /** @var UploadedFile|null $legacyFile */
        $legacyFile = $request->file('knowledge_file');
        if ($legacyFile instanceof UploadedFile) {
            $files[] = $legacyFile;
        }

        $multiFiles = $request->file('knowledge_files', []);
        if (is_array($multiFiles)) {
            foreach ($multiFiles as $file) {
                if ($file instanceof UploadedFile) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, string>  $storedPaths
     * @return array<int, array{content:string,file_type:string,original_name:string}>
     */
    private function parseUploadedKnowledgeFiles(array $uploadedFiles, array &$storedPaths): array
    {
        $parsedFiles = [];
        $parsedBytes = 0;

        foreach ($uploadedFiles as $uploadedFile) {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($uploadedFile);
            $storedPaths[] = $storedRelativePath;
            $parsed = $this->parseUploadedKnowledgeFile(
                Storage::disk('local')->path($storedRelativePath),
                $uploadedFile->getClientOriginalName()
            );

            $parsedFiles[] = [
                'content' => $parsed['content'],
                'file_type' => $parsed['file_type'],
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
            ];
            $parsedBytes += strlen((string) $parsed['content']);
            if ($parsedBytes > self::MAX_KNOWLEDGE_BYTES) {
                throw ValidationException::withMessages([
                    'knowledge_files' => __('admin.knowledge_bases.error.content_too_large'),
                ]);
            }
        }

        return $parsedFiles;
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function mergeKnowledgeSources(string $manualContent, array $parsedFiles): string
    {
        if ($manualContent !== '' && $parsedFiles === []) {
            return $manualContent;
        }

        $blocks = [];
        if ($manualContent !== '') {
            $blocks[] = "# 手动输入内容\n\n".$manualContent;
        }

        foreach ($parsedFiles as $parsedFile) {
            $fileName = trim((string) $parsedFile['original_name']);
            $blocks[] = '# 文件：'.$fileName."\n\n".trim((string) $parsedFile['content']);
        }

        return $this->normalizeKnowledgeText(implode("\n\n---\n\n", $blocks));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    private function inferKnowledgeName(array $uploadedFiles): string
    {
        if ($uploadedFiles === []) {
            return '';
        }

        $firstName = pathinfo((string) $uploadedFiles[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }

        return $firstName === ''
            ? __('admin.knowledge_bases.imported_multi_file_name', ['count' => count($uploadedFiles)])
            : __('admin.knowledge_bases.imported_multi_file_name_with_first', [
                'name' => $firstName,
                'count' => count($uploadedFiles),
            ]);
    }

    private function inferKnowledgeNameFromContent(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
            $candidate = trim(strip_tags($candidate));
            $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 60, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    private function resolveKnowledgeFileType(string $requestedType, string $manualContent, array $parsedFiles): string
    {
        if ($parsedFiles === []) {
            return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
        }

        if ($manualContent !== '' || count($parsedFiles) > 1) {
            return 'markdown';
        }

        $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');

        return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    private function encodeKnowledgeFilePaths(array $storedPaths): string
    {
        if ($storedPaths === []) {
            return '';
        }

        if (count($storedPaths) === 1) {
            return (string) $storedPaths[0];
        }

        return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{content:string,file_type:string}
     */
    private function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'txt' || $extension === 'md') {
            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => $extension === 'md' ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
    }

    /**
     * 清理上传失败或删除后的知识文件。
     */
    private function cleanupKnowledgeFile(string $relativePath): void
    {
        $this->cleanupKnowledgeFiles($this->decodeKnowledgeFilePaths($relativePath));
    }

    /**
     * @return array<int, string>
     */
    private function decodeKnowledgeFilePaths(string $storedValue): array
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return [];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($path): bool => is_string($path) && trim($path) !== ''));
        }

        return [$storedValue];
    }

    /**
     * @param  array<int, string>  $relativePaths
     */
    private function cleanupKnowledgeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $this->deleteKnowledgeFilePath($relativePath);
        }
    }

    private function deleteKnowledgeFilePath(string $relativePath): void
    {
        $relativePath = $this->normalizeDeletableKnowledgePath($relativePath);
        if ($relativePath === null) {
            return;
        }

        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    private function normalizeDeletableKnowledgePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:\//', $relativePath) === 1
            || str_contains('/'.$relativePath.'/', '/../')
        ) {
            return null;
        }

        foreach (['knowledge-bases/', 'uploads/knowledge/'] as $allowedPrefix) {
            if (str_starts_with($relativePath, $allowedPrefix)) {
                return $relativePath;
            }
        }

        return null;
    }

    /**
     * 将文本转换为 UTF-8，兼容上传文件编码差异。
     */
    private function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    /**
     * 统一知识文本换行与空白，提升分块稳定性。
     */
    private function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * 从 docx 提取正文（优先 ZipArchive，失败时降级为空字符串）。
     */
    private function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive') || ! class_exists('XMLReader')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $stat = $zip->statName('word/document.xml');
        if (! is_array($stat)) {
            $zip->close();

            return '';
        }

        $uncompressedSize = max(0, (int) ($stat['size'] ?? 0));
        $compressedSize = max(1, (int) ($stat['comp_size'] ?? 0));
        if (
            $uncompressedSize > self::MAX_DOCX_XML_BYTES
            || ($uncompressedSize / $compressedSize) > self::MAX_DOCX_COMPRESSION_RATIO
        ) {
            $zip->close();
            throw ValidationException::withMessages([
                'knowledge_files' => __('admin.knowledge_bases.error.docx_expansion_too_large'),
            ]);
        }

        $source = $zip->getStream('word/document.xml');
        $temporary = tmpfile();
        if (! is_resource($source) || ! is_resource($temporary)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temporary)) {
                fclose($temporary);
            }
            $zip->close();

            return '';
        }

        $copiedBytes = stream_copy_to_stream($source, $temporary, self::MAX_DOCX_XML_BYTES + 1);
        fclose($source);
        $zip->close();
        if (! is_int($copiedBytes) || $copiedBytes > self::MAX_DOCX_XML_BYTES) {
            fclose($temporary);
            throw ValidationException::withMessages([
                'knowledge_files' => __('admin.knowledge_bases.error.docx_expansion_too_large'),
            ]);
        }

        $metadata = stream_get_meta_data($temporary);
        $temporaryPath = (string) ($metadata['uri'] ?? '');
        $reader = new \XMLReader;
        if ($temporaryPath === '' || ! @$reader->open($temporaryPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            fclose($temporary);

            return '';
        }

        $wordNamespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $textOutput = tmpfile();
        if (! is_resource($textOutput)) {
            $reader->close();
            fclose($temporary);

            return '';
        }
        $contentBytes = 0;
        while ($reader->read()) {
            if (
                $reader->nodeType === \XMLReader::ELEMENT
                && $reader->localName === 't'
                && $reader->namespaceURI === $wordNamespace
            ) {
                $value = trim($reader->readString());
                if ($value !== '') {
                    $contentBytes += strlen($value) + 1;
                    if ($contentBytes > self::MAX_KNOWLEDGE_BYTES) {
                        $reader->close();
                        fclose($temporary);
                        fclose($textOutput);
                        throw ValidationException::withMessages([
                            'knowledge_files' => __('admin.knowledge_bases.error.content_too_large'),
                        ]);
                    }
                    fwrite($textOutput, $value."\n");
                }
            }
        }
        $reader->close();
        fclose($temporary);
        rewind($textOutput);
        $content = stream_get_contents($textOutput, self::MAX_KNOWLEDGE_BYTES + 1);
        fclose($textOutput);

        return is_string($content) ? $this->normalizeKnowledgeText($content) : '';
    }

    private function assertKnowledgeContentSize(string $content): void
    {
        if (strlen($content) <= self::MAX_KNOWLEDGE_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'content' => __('admin.knowledge_bases.error.content_too_large'),
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    private function assertUploadedKnowledgeTotalSize(array $uploadedFiles): void
    {
        $totalBytes = 0;
        foreach ($uploadedFiles as $uploadedFile) {
            $totalBytes += max(0, (int) $uploadedFile->getSize());
        }

        if ($totalBytes <= self::MAX_KNOWLEDGE_BYTES) {
            return;
        }

        throw ValidationException::withMessages([
            'knowledge_files' => __('admin.knowledge_bases.error.total_files_too_large'),
        ]);
    }
}
