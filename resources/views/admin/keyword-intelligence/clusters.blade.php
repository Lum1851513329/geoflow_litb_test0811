@extends('admin.layouts.app')
@section('content')
<div class="space-y-5 px-4 sm:px-0"><div class="flex justify-between"><h1 class="text-2xl font-bold">{{ __('admin.keyword_intelligence.clusters.title') }}</h1><a href="{{ route('admin.keyword-intelligence.index') }}">{{ __('admin.keyword_intelligence.nav.overview') }}</a></div>
<form method="GET" class="flex gap-2 rounded bg-white p-4"><input name="language" value="{{ request('language') }}" class="rounded border p-2" placeholder="{{ __('admin.keyword_intelligence.filters.language') }}"><input name="market" value="{{ request('market') }}" class="rounded border p-2" placeholder="{{ __('admin.keyword_intelligence.filters.market') }}"><button>{{ __('admin.keyword_intelligence.filters.apply') }}</button></form>
<section class="rounded bg-white p-4"><h2 class="font-semibold">{{ __('admin.keyword_intelligence.clusters.suggestions') }}</h2><div class="mt-3 grid gap-3 md:grid-cols-2">
@forelse($suggestions as $candidate)
@php($suggestion = $candidate['data'])
<form method="POST" action="{{ route('admin.keyword-intelligence.clusters.confirm') }}" class="rounded border p-3">
@csrf
<input type="hidden" name="candidate_token" value="{{ $candidate['token'] }}">
<h3 class="font-medium">{{ $suggestion['name'] }}</h3><p class="text-sm text-gray-500">{{ count($suggestion['keywords']) }} {{ __('admin.keyword_intelligence.clusters.keywords') }}</p><button class="mt-2 text-blue-600">{{ __('admin.keyword_intelligence.actions.confirm_cluster') }}</button></form>
@empty
<p class="text-gray-500">{{ __('admin.keyword_intelligence.clusters.no_suggestions') }}</p>
@endforelse
</div></section>
<div class="rounded bg-white"><table class="min-w-full text-sm"><thead><tr class="border-b text-left"><th class="p-3">{{ __('admin.keyword_intelligence.columns.cluster') }}</th><th>{{ __('admin.keyword_intelligence.columns.primary') }}</th><th>{{ __('admin.keyword_intelligence.columns.score') }}</th><th>{{ __('admin.keyword_intelligence.columns.status') }}</th></tr></thead><tbody>@forelse($clusters as $cluster)<tr class="border-b"><td class="p-3"><a class="text-blue-600" href="{{ route('admin.keyword-intelligence.clusters.show', $cluster) }}">{{ $cluster->name }}</a></td><td>{{ $cluster->primaryKeyword?->keyword }}</td><td>{{ $cluster->latest_opportunity_score ?? '—' }}</td><td>{{ $cluster->status }}</td></tr>@empty<tr><td colspan="4" class="p-8 text-center">{{ __('admin.keyword_intelligence.empty.clusters') }}</td></tr>@endforelse</tbody></table></div>{{ $clusters->links() }}</div>
@endsection
