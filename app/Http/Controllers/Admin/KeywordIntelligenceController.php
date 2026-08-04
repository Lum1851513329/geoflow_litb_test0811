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
use App\Services\GeoFlow\Keywords\KeywordClusterService;
use App\Services\GeoFlow\Keywords\KeywordImportPreview;
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

    public function preview(PreviewKeywordImportRequest $request, KeywordImportPreview $preview): View
    {
        $file = $request->file('workbook');
        $result = $preview->build($file->getRealPath(), $request->validated('source_type'), $request->validated('mapping', []));

        return view('admin.keyword-intelligence.import', $this->importViewData($result));
    }

    public function confirm(ConfirmKeywordImportRequest $request, KeywordImportService $service): RedirectResponse
    {
        $file = $request->file('workbook');
        $data = $request->validated();
        $batch = $service->createBatch([
            'source_path' => $file->getRealPath(),
            'original_filename' => $file->getClientOriginalName(),
            'library_id' => $data['library_id'],
            'source_type' => $data['source_type'],
            'mapping_json' => $data['mapping'] ?? [],
            'metadata_json' => array_filter(['language_code' => $data['language_code'] ?? null, 'market_code' => isset($data['market_code']) ? strtoupper($data['market_code']) : null]),
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'created_by' => (int) $request->user('admin')->id,
        ]);
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

    public function taxonomy(): View
    {
        return view('admin.keyword-intelligence.taxonomy', [
            'pageTitle' => __('admin.keyword_intelligence.taxonomy.title'), 'activeMenu' => 'materials',
            'taxonomies' => KeywordTaxonomy::query()->with(['nodes' => fn ($query) => $query->withCount('keywords')->orderBy('path')])->get(),
            'keywords' => Keyword::query()->where('status', 'active')->orderBy('keyword')->limit(200)->get(),
        ]);
    }

    public function storeNode(StoreKeywordNodeRequest $request, KeywordTaxonomyService $service): RedirectResponse
    {
        $taxonomy = KeywordTaxonomy::query()->findOrFail($request->integer('taxonomy_id'));
        $service->createNode($taxonomy, $request->validated());

        return back()->with('message', __('admin.keyword_intelligence.messages.node_created'));
    }

    public function moveNode(MoveKeywordNodeRequest $request, KeywordTaxonomyService $service): RedirectResponse
    {
        $node = KeywordNode::query()->findOrFail($request->integer('node_id'));
        $parent = $request->integer('parent_id') > 0 ? KeywordNode::query()->findOrFail($request->integer('parent_id')) : null;
        $service->moveNode($node, $parent, $request->integer('sort_order'));

        return back()->with('message', __('admin.keyword_intelligence.messages.node_moved'));
    }

    public function assign(Request $request, KeywordNode $node, KeywordTaxonomyService $service): RedirectResponse
    {
        $data = $request->validate(['keyword_ids' => ['required', 'array'], 'keyword_ids.*' => ['integer', 'exists:keywords,id'], 'primary_keyword_id' => ['nullable', 'integer', 'exists:keywords,id']]);
        $service->assignKeywords($node, $data['keyword_ids'], $data['primary_keyword_id'] ?? null);

        return back()->with('message', __('admin.keyword_intelligence.messages.keywords_assigned'));
    }

    public function clusters(): View
    {
        return view('admin.keyword-intelligence.clusters', ['pageTitle' => __('admin.keyword_intelligence.clusters.title'), 'activeMenu' => 'materials', 'clusters' => KeywordCluster::query()->with('primaryKeyword')->latest()->paginate(20)]);
    }

    public function showCluster(KeywordCluster $cluster): View
    {
        return view('admin.keyword-intelligence.cluster-detail', ['pageTitle' => $cluster->name, 'activeMenu' => 'materials', 'cluster' => $cluster->load(['primaryKeyword', 'keywords', 'taxonomyNode', 'opportunityScores' => fn ($query) => $query->latest('period_end')])]);
    }

    public function confirmCluster(Request $request, KeywordClusterService $service): RedirectResponse
    {
        $data = $request->validate(['suggestion' => ['required', 'array']]);
        try {
            $cluster = $service->confirm($data['suggestion'], (int) $request->user('admin')->id);
        } catch (DomainException) {
            return back()->withErrors(['suggestion' => __('admin.keyword_intelligence.validation.cluster_invalid')]);
        }

        return redirect()->route('admin.keyword-intelligence.clusters.show', $cluster)->with('message', __('admin.keyword_intelligence.messages.cluster_confirmed'));
    }

    public function score(Request $request, KeywordCluster $cluster, KeywordOpportunityScoringService $service): RedirectResponse
    {
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);
        $service->score($cluster, new DatePeriod(new DateTimeImmutable($data['period_start']), new DateInterval('P1D'), new DateTimeImmutable($data['period_end'])));

        return back()->with('message', __('admin.keyword_intelligence.messages.score_updated'));
    }

    private function importViewData(?array $preview = null): array
    {
        return ['pageTitle' => __('admin.keyword_intelligence.import.title'), 'activeMenu' => 'materials', 'libraries' => KeywordLibrary::query()->orderBy('name')->get(), 'preview' => $preview];
    }
}
