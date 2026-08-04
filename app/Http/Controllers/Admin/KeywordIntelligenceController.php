<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmKeywordImportRequest;
use App\Http\Requests\Admin\MoveKeywordNodeRequest;
use App\Http\Requests\Admin\PreviewKeywordImportRequest;
use App\Http\Requests\Admin\StoreKeywordNodeRequest;
use App\Jobs\ImportKeywordWorkbookJob;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordImportBatch;
use App\Models\KeywordLibrary;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use App\Services\GeoFlow\Keywords\KeywordClusterCandidateToken;
use App\Services\GeoFlow\Keywords\KeywordClusterService;
use App\Services\GeoFlow\Keywords\KeywordClusterSuggestionService;
use App\Services\GeoFlow\Keywords\KeywordImportPreviewSession;
use App\Services\GeoFlow\Keywords\KeywordImportRevertService;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use App\Services\GeoFlow\Keywords\KeywordOpportunityScoringService;
use App\Services\GeoFlow\Keywords\KeywordTaxonomyService;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class KeywordIntelligenceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Keyword::query()->with('library')->latest('id');
        foreach (['keyword_type' => 'type', 'search_intent' => 'intent', 'brand_relation' => 'brand_relation', 'language_code' => 'language', 'market_scope' => 'market'] as $column => $input) {
            if ($request->filled($input)) {
                $query->where($column, $request->string($input)->toString());
            }
        }
        if ($request->filled('q')) {
            $query->where('keyword', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('q')->toString()).'%');
        }

        return view('admin.keyword-intelligence.index', [
            'pageTitle' => __('admin.keyword_intelligence.overview.title'),
            'activeMenu' => 'materials',
            'keywords' => $query->paginate(20)->withQueryString(),
            'batches' => KeywordImportBatch::query()->latest()->limit(10)->get(),
            'clusterCount' => KeywordCluster::query()->count(),
        ]);
    }

    public function import(): View
    {
        return view('admin.keyword-intelligence.import', $this->importViewData());
    }

    public function preview(PreviewKeywordImportRequest $request, KeywordImportPreviewSession $session): View|RedirectResponse
    {
        try {
            $result = $session->begin($request->file('workbook'), $request->validated('source_type'), $request->validated('mapping', []));
        } catch (InvalidArgumentException) {
            return back()->withErrors(['workbook' => __('admin.keyword_intelligence.validation.workbook_invalid')]);
        }

        return view('admin.keyword-intelligence.import', $this->importViewData($result['preview']) + [
            'previewToken' => $result['token'],
            'mappingHeaders' => $result['headers'],
            'resolvedMapping' => $result['resolved_mapping'],
        ]);
    }

    public function confirm(ConfirmKeywordImportRequest $request, KeywordImportService $service, KeywordImportPreviewSession $session): RedirectResponse
    {
        $data = $request->validated();
        try {
            $preview = $session->resolve($data['preview_token'], $data['source_type']);
            $batch = $service->createBatch([
                'source_path' => $preview['absolute_path'],
                'original_filename' => $preview['original_filename'],
                'library_id' => $data['library_id'],
                'source_type' => $data['source_type'],
                'mapping_json' => $data['mapping'] ?? [],
                'metadata_json' => array_filter(['language_code' => $data['language_code'] ?? null, 'market_code' => isset($data['market_code']) ? strtoupper($data['market_code']) : null]),
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'created_by' => (int) $request->user('admin')->id,
            ]);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['preview_token' => __('admin.keyword_intelligence.validation.preview_token_invalid')]);
        }
        $session->forget($preview['path']);
        ImportKeywordWorkbookJob::dispatch($batch->id);

        return redirect()->route('admin.keyword-intelligence.index')->with('message', __('admin.keyword_intelligence.messages.import_queued'));
    }

    public function status(KeywordImportBatch $batch): JsonResponse
    {
        return response()->json($batch->only(['id', 'status', 'total_count', 'processed_count', 'imported_count', 'duplicate_count', 'rejected_count', 'retained_shared_keyword_count', 'error_summary']));
    }

    public function revert(KeywordImportBatch $batch, KeywordImportRevertService $service): RedirectResponse
    {
        try {
            $service->revert($batch);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['batch' => __('admin.keyword_intelligence.validation.revert_status')]);
        }

        return back()->with('message', __('admin.keyword_intelligence.messages.reverted'));
    }

    public function taxonomy(Request $request): View
    {
        $keywords = Keyword::query()->where('status', 'active')->orderBy('keyword');
        foreach (['keyword_language' => 'language_code', 'keyword_market' => 'market_scope'] as $input => $column) {
            if ($request->filled($input)) {
                $keywords->where($column, $request->string($input)->toString());
            }
        }
        if ($request->filled('keyword_q')) {
            $keywords->where('keyword', 'like', '%'.$request->string('keyword_q')->toString().'%');
        }

        return view('admin.keyword-intelligence.taxonomy', [
            'pageTitle' => __('admin.keyword_intelligence.taxonomy.title'), 'activeMenu' => 'materials',
            'taxonomies' => KeywordTaxonomy::query()->with(['nodes' => fn ($query) => $query->withCount('keywords')->orderBy('path')])->get(),
            'keywordOptions' => $keywords->paginate(50, ['*'], 'keyword_page')->withQueryString(),
        ]);
    }

    public function storeNode(StoreKeywordNodeRequest $request, KeywordTaxonomyService $service): RedirectResponse
    {
        $taxonomy = KeywordTaxonomy::query()->findOrFail($request->integer('taxonomy_id'));
        try {
            $service->createNode($taxonomy, $request->validated());
        } catch (DomainException) {
            return back()->withErrors(['name' => __('admin.keyword_intelligence.validation.taxonomy_invalid')]);
        }

        return back()->with('message', __('admin.keyword_intelligence.messages.node_created'));
    }

    public function moveNode(MoveKeywordNodeRequest $request, KeywordTaxonomyService $service): RedirectResponse
    {
        $node = KeywordNode::query()->findOrFail($request->integer('node_id'));
        $parent = $request->integer('parent_id') > 0 ? KeywordNode::query()->findOrFail($request->integer('parent_id')) : null;
        try {
            $service->moveNode($node, $parent, $request->integer('sort_order'));
        } catch (DomainException) {
            return back()->withErrors(['parent_id' => __('admin.keyword_intelligence.validation.taxonomy_invalid')]);
        }

        return back()->with('message', __('admin.keyword_intelligence.messages.node_moved'));
    }

    public function assign(Request $request, KeywordNode $node, KeywordTaxonomyService $service): RedirectResponse
    {
        $data = $request->validate(['keyword_ids' => ['required', 'array'], 'keyword_ids.*' => ['integer', 'exists:keywords,id'], 'primary_keyword_id' => ['nullable', 'integer', 'exists:keywords,id']]);
        try {
            $service->assignKeywords($node, $data['keyword_ids'], $data['primary_keyword_id'] ?? null);
        } catch (DomainException) {
            return back()->withErrors(['keyword_ids' => __('admin.keyword_intelligence.validation.taxonomy_invalid')]);
        }

        return back()->with('message', __('admin.keyword_intelligence.messages.keywords_assigned'));
    }

    public function clusters(Request $request, KeywordClusterSuggestionService $suggestions, KeywordClusterCandidateToken $tokens): View
    {
        $candidateKeywords = Keyword::query()->where('status', 'active')->whereDoesntHave('clusters')->with('taxonomyNodes')->orderBy('id')->limit(500);
        foreach (['language' => 'language_code', 'market' => 'market_scope'] as $input => $column) {
            if ($request->filled($input)) {
                $candidateKeywords->where($column, $request->string($input)->toString());
            }
        }

        return view('admin.keyword-intelligence.clusters', [
            'pageTitle' => __('admin.keyword_intelligence.clusters.title'),
            'activeMenu' => 'materials',
            'clusters' => KeywordCluster::query()->with('primaryKeyword')->latest()->paginate(20),
            'suggestions' => collect($suggestions->suggest($candidateKeywords->get()))->map(fn (array $suggestion): array => ['data' => $suggestion, 'token' => $tokens->issue($suggestion)])->all(),
        ]);
    }

    public function showCluster(KeywordCluster $cluster): View
    {
        return view('admin.keyword-intelligence.cluster-detail', ['pageTitle' => $cluster->name, 'activeMenu' => 'materials', 'cluster' => $cluster->load(['primaryKeyword', 'keywords', 'taxonomyNode', 'opportunityScores' => fn ($query) => $query->latest('period_end')])]);
    }

    public function confirmCluster(Request $request, KeywordClusterService $service, KeywordClusterCandidateToken $tokens): RedirectResponse
    {
        $data = $request->validate(['candidate_token' => ['required', 'string', 'max:262144']]);
        try {
            $cluster = $service->confirm($tokens->resolve($data['candidate_token']), (int) $request->user('admin')->id);
        } catch (DomainException|InvalidArgumentException) {
            return back()->withErrors(['candidate_token' => __('admin.keyword_intelligence.validation.cluster_invalid')]);
        }

        return redirect()->route('admin.keyword-intelligence.clusters.show', $cluster)->with('message', __('admin.keyword_intelligence.messages.cluster_confirmed'));
    }

    public function score(Request $request, KeywordCluster $cluster, KeywordOpportunityScoringService $service): RedirectResponse
    {
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);
        try {
            $service->score($cluster, new DatePeriod(new DateTimeImmutable($data['period_start']), new DateInterval('P1D'), new DateTimeImmutable($data['period_end'])));
        } catch (DomainException|InvalidArgumentException) {
            return back()->withErrors(['period_start' => __('admin.keyword_intelligence.validation.score_invalid')]);
        }

        return back()->with('message', __('admin.keyword_intelligence.messages.score_updated'));
    }

    private function importViewData(?array $preview = null): array
    {
        return ['pageTitle' => __('admin.keyword_intelligence.import.title'), 'activeMenu' => 'materials', 'libraries' => KeywordLibrary::query()->orderBy('name')->get(), 'preview' => $preview];
    }
}
