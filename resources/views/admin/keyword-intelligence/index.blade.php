@extends('admin.layouts.app')
@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold">{{ __('admin.keyword_intelligence.overview.title') }}</h1><p class="text-sm text-gray-500">{{ __('admin.keyword_intelligence.overview.subtitle') }}</p></div><div class="flex gap-2"><a class="rounded border px-3 py-2" href="{{ route('admin.keyword-libraries.index') }}">{{ __('admin.keyword_intelligence.legacy_libraries') }}</a><a class="rounded bg-blue-600 px-3 py-2 text-white" href="{{ route('admin.keyword-intelligence.import') }}">{{ __('admin.keyword_intelligence.nav.import') }}</a></div></div>
    <nav class="flex gap-4 text-sm"><a href="{{ route('admin.keyword-intelligence.index') }}">{{ __('admin.keyword_intelligence.nav.overview') }}</a><a href="{{ route('admin.keyword-intelligence.taxonomy') }}">{{ __('admin.keyword_intelligence.nav.taxonomy') }}</a><a href="{{ route('admin.keyword-intelligence.clusters') }}">{{ __('admin.keyword_intelligence.nav.clusters') }} ({{ $clusterCount }})</a></nav>
    <section class="rounded bg-white p-4">
        <h2 class="font-semibold">{{ __('admin.keyword_intelligence.overview.classification.title') }}</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-3"><div class="rounded border p-3"><p class="text-xs text-gray-500">{{ __('admin.keyword_intelligence.overview.classification.total_keywords') }}</p><p class="text-xl font-bold">{{ number_format($totalKeywordCount) }}</p></div><div class="rounded border p-3"><p class="text-xs text-gray-500">{{ __('admin.keyword_intelligence.overview.classification.total_nodes') }}</p><p class="text-xl font-bold">{{ number_format($totalNodeCount) }}</p></div><div class="rounded border p-3"><p class="text-xs text-gray-500">{{ __('admin.keyword_intelligence.overview.classification.total_clusters') }}</p><p class="text-xl font-bold">{{ number_format($clusterCount) }}</p></div></div>

        {{-- Fixed palette shared across every chart in this section so segment N (0-indexed) always maps to the same color, whichever breakdown it appears in. --}}
        @php $chartPalette = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316']; @endphp

        <div class="mt-4">
            <h3 class="text-sm font-semibold text-gray-700">{{ __('admin.keyword_intelligence.overview.classification.categories_title') }}</h3>
            {{-- Horizontal bar chart: width is relative to the largest subtree in this set (bar_percentage, computed server-side), not the grand total, so the biggest category renders a full-width bar. --}}
            @if($categoryBreakdown->isNotEmpty())
            <div class="mt-2 space-y-1.5">
                @foreach($categoryBreakdown as $category)
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-40 shrink-0 truncate" title="{{ $category->name }}">{{ $category->name }}</span>
                    <div class="h-3 flex-1 rounded bg-gray-100"><div class="h-3 rounded bg-blue-600" style="width: {{ $category->bar_percentage }}%;"></div></div>
                    <span class="w-16 shrink-0 text-right font-medium">{{ number_format($category->subtree_keyword_count) }}</span>
                </div>
                @endforeach
            </div>
            @endif
            <div class="mt-3 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b text-left"><th class="p-2">{{ __('admin.keyword_intelligence.overview.classification.category_name') }}</th><th class="p-2">{{ __('admin.keyword_intelligence.overview.classification.category_own') }}</th><th class="p-2">{{ __('admin.keyword_intelligence.overview.classification.category_subtree_nodes') }}</th><th class="p-2">{{ __('admin.keyword_intelligence.overview.classification.category_subtree_keywords') }}</th></tr></thead><tbody>@forelse($categoryBreakdown as $category)<tr class="border-b"><td class="p-2">{{ $category->name }}</td><td class="p-2">{{ $category->keywords_count }}</td><td class="p-2">{{ $category->subtree_node_count }}</td><td class="p-2">{{ $category->subtree_keyword_count }}</td></tr>@empty<tr><td colspan="4" class="p-4 text-center text-gray-500">{{ __('admin.keyword_intelligence.overview.classification.categories_empty') }}</td></tr>@endforelse</tbody></table></div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">{{ __('admin.keyword_intelligence.overview.classification.by_type') }}</h3>
                {{-- Donut chart: conic-gradient segments use the cumulative segment_start/segment_end stops computed server-side (running integer count / total), so stops always sum to exactly 100% with no drift. --}}
                @if($typeBreakdown->isNotEmpty())
                @php $typeStops = $typeBreakdown->map(fn ($row, $i) => $chartPalette[$i % count($chartPalette)].' '.$row->segment_start.'% '.$row->segment_end.'%')->implode(', '); @endphp
                <div class="mt-2 flex items-center gap-3">
                    <div class="relative h-24 w-24 shrink-0 rounded-full" style="background: conic-gradient({{ $typeStops }});"><div class="absolute inset-[16%] rounded-full bg-white"></div></div>
                    <ul class="flex-1 space-y-1 text-xs">@foreach($typeBreakdown as $i => $row)<li class="flex items-center justify-between gap-2"><span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $chartPalette[$i % count($chartPalette)] }};"></span>{{ $row->keyword_type ?: '—' }}</span><span class="text-gray-600">{{ $row->percentage }}%</span></li>@endforeach</ul>
                </div>
                @endif
                <ul class="mt-3 space-y-1 text-sm">@foreach($typeBreakdown as $row)<li class="flex justify-between border-b py-1"><span>{{ $row->keyword_type ?: '—' }}</span><span>{{ $row->total }}</span></li>@endforeach</ul>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">{{ __('admin.keyword_intelligence.overview.classification.by_intent') }}</h3>
                @if($intentBreakdown->isNotEmpty())
                @php $intentStops = $intentBreakdown->map(fn ($row, $i) => $chartPalette[$i % count($chartPalette)].' '.$row->segment_start.'% '.$row->segment_end.'%')->implode(', '); @endphp
                <div class="mt-2 flex items-center gap-3">
                    <div class="relative h-24 w-24 shrink-0 rounded-full" style="background: conic-gradient({{ $intentStops }});"><div class="absolute inset-[16%] rounded-full bg-white"></div></div>
                    <ul class="flex-1 space-y-1 text-xs">@foreach($intentBreakdown as $i => $row)<li class="flex items-center justify-between gap-2"><span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $chartPalette[$i % count($chartPalette)] }};"></span>{{ $row->search_intent ?: '—' }}</span><span class="text-gray-600">{{ $row->percentage }}%</span></li>@endforeach</ul>
                </div>
                @endif
                <ul class="mt-3 space-y-1 text-sm">@foreach($intentBreakdown as $row)<li class="flex justify-between border-b py-1"><span>{{ $row->search_intent ?: '—' }}</span><span>{{ $row->total }}</span></li>@endforeach</ul>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-700">{{ __('admin.keyword_intelligence.overview.classification.by_language') }}</h3>
                @if($languageBreakdown->isNotEmpty())
                @php $languageStops = $languageBreakdown->map(fn ($row, $i) => $chartPalette[$i % count($chartPalette)].' '.$row->segment_start.'% '.$row->segment_end.'%')->implode(', '); @endphp
                <div class="mt-2 flex items-center gap-3">
                    <div class="relative h-24 w-24 shrink-0 rounded-full" style="background: conic-gradient({{ $languageStops }});"><div class="absolute inset-[16%] rounded-full bg-white"></div></div>
                    <ul class="flex-1 space-y-1 text-xs">@foreach($languageBreakdown as $i => $row)<li class="flex items-center justify-between gap-2"><span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $chartPalette[$i % count($chartPalette)] }};"></span>{{ $row->language_code ?: '—' }}</span><span class="text-gray-600">{{ $row->percentage }}%</span></li>@endforeach</ul>
                </div>
                @endif
                <ul class="mt-3 space-y-1 text-sm">@foreach($languageBreakdown as $row)<li class="flex justify-between border-b py-1"><span>{{ $row->language_code ?: '—' }}</span><span>{{ $row->total }}</span></li>@endforeach</ul>
            </div>
        </div>
    </section>
    <form method="GET" class="grid gap-3 rounded bg-white p-4 md:grid-cols-6">@csrf<input class="rounded border p-2" name="q" value="{{ request('q') }}" placeholder="{{ __('admin.keyword_intelligence.filters.search') }}"><input class="rounded border p-2" name="type" value="{{ request('type') }}" placeholder="{{ __('admin.keyword_intelligence.filters.type') }}"><input class="rounded border p-2" name="intent" value="{{ request('intent') }}" placeholder="{{ __('admin.keyword_intelligence.filters.intent') }}"><input class="rounded border p-2" name="language" value="{{ request('language') }}" placeholder="{{ __('admin.keyword_intelligence.filters.language') }}"><input class="rounded border p-2" name="market" value="{{ request('market') }}" placeholder="{{ __('admin.keyword_intelligence.filters.market') }}"><button class="rounded bg-gray-900 text-white">{{ __('admin.keyword_intelligence.filters.apply') }}</button></form>
    <div class="overflow-x-auto rounded bg-white"><table class="min-w-full text-sm"><thead><tr class="border-b text-left"><th class="p-3">{{ __('admin.keyword_intelligence.columns.keyword') }}</th><th>{{ __('admin.keyword_intelligence.columns.type') }}</th><th>{{ __('admin.keyword_intelligence.columns.intent') }}</th><th>{{ __('admin.keyword_intelligence.columns.scope') }}</th></tr></thead><tbody>@forelse($keywords as $keyword)<tr class="border-b"><td class="p-3">{{ $keyword->keyword }}</td><td>{{ $keyword->keyword_type ?: '—' }}</td><td>{{ $keyword->search_intent ?: '—' }}</td><td>{{ $keyword->language_code ?: '—' }} / {{ $keyword->market_scope }}</td></tr>@empty<tr><td colspan="4" class="p-8 text-center text-gray-500">{{ __('admin.keyword_intelligence.empty.keywords') }}</td></tr>@endforelse</tbody></table></div>{{ $keywords->links() }}
    <section class="rounded bg-white p-4"><h2 class="font-semibold">{{ __('admin.keyword_intelligence.import.recent') }}</h2><div class="mt-3 space-y-2">@forelse($batches as $batch)<div class="border-b py-2" data-status-url="{{ route('admin.keyword-intelligence.import.status', $batch) }}"><div class="flex items-center justify-between"><span>#{{ $batch->id }} {{ $batch->original_filename }}</span><span>{{ __('admin.keyword_intelligence.status.'.$batch->status) }} · {{ $batch->processed_count }} / {{ $batch->total_count }}</span>@if(in_array($batch->status, ['completed','failed']))<form method="POST" action="{{ route('admin.keyword-intelligence.import.revert', $batch) }}">@csrf<button class="text-red-600">{{ __('admin.keyword_intelligence.actions.revert') }}</button></form>@endif</div>@if($batch->error_summary)<p class="mt-1 text-sm text-red-700">{{ $batch->error_summary }}</p>@endif @if($batch->status === 'failed')<p class="text-sm text-amber-700">{{ __('admin.keyword_intelligence.import.failed_recovery') }}</p>@endif</div>@empty<p class="text-gray-500">{{ __('admin.keyword_intelligence.status.empty') }}</p>@endforelse</div></section>
</div>
@endsection
