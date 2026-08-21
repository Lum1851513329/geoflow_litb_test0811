@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.knowledge-bases.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.knowledge_bases.retrieval_preview_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.knowledge_bases.retrieval_preview_subtitle') }}</p>
                </div>
            </div>
        </div>

        <section class="mb-8 bg-white shadow rounded-lg">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">{{ __('admin.knowledge_bases.retrieval_preview_form_title') }}</h2>
            </div>
            <form method="GET" action="{{ route('admin.knowledge-bases.retrieval-preview') }}" class="grid grid-cols-1 gap-4 px-6 py-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.knowledge_bases.field_brand') }}</label>
                    <select name="brand_id" class="w-full rounded-md border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        <option value="">{{ __('admin.knowledge_bases.filter_all_brands') }}</option>
                        @foreach ($brandOptions as $brand)
                            <option value="{{ (int) $brand->id }}" @selected((int) ($filters['brand_id'] ?? 0) === (int) $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.knowledge_bases.field_site') }}</label>
                    <select name="site_id" class="w-full rounded-md border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        <option value="">{{ __('admin.knowledge_bases.filter_all_sites') }}</option>
                        @foreach ($siteOptions as $site)
                            <option value="{{ (int) $site->id }}" @selected((int) ($filters['site_id'] ?? 0) === (int) $site->id)>{{ $site->canonical_host }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.knowledge_bases.field_language') }}</label>
                    <select name="language_code" class="w-full rounded-md border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500">
                        @foreach ($languageOptions as $language)
                            <option value="{{ $language }}" @selected(($filters['language_code'] ?? 'en') === $language)>{{ $language }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600">{{ __('admin.knowledge_bases.retrieval_preview_field_query') }}</label>
                    <input
                        type="text"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="{{ __('admin.knowledge_bases.retrieval_preview_query_placeholder') }}"
                        class="w-full rounded-md border-gray-300 text-sm focus:border-orange-500 focus:ring-orange-500"
                    >
                </div>
                <div class="flex items-end gap-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center justify-center rounded-md bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700">
                        <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.knowledge_bases.retrieval_preview_run') }}
                    </button>
                    <a href="{{ route('admin.knowledge-bases.retrieval-preview') }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        {{ __('admin.button.reset') }}
                    </a>
                </div>
            </form>
        </section>

        @if ($scopeError)
            <div class="mb-8 rounded-lg border border-red-200 bg-red-50 px-6 py-4 text-sm text-red-700">
                {{ $scopeError }}
            </div>
        @endif

        <div class="bg-white shadow rounded-lg">
            <div class="border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.knowledge_bases.retrieval_preview_results_title') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('admin.knowledge_bases.retrieval_preview_mvp_note') }}</p>
            </div>

            @if (! $searched)
                <div class="px-6 py-8 text-center text-sm text-gray-500">
                    {{ __('admin.knowledge_bases.retrieval_preview_prompt') }}
                </div>
            @elseif (empty($results))
                <div class="px-6 py-8 text-center text-sm text-gray-500">
                    {{ __('admin.knowledge_bases.retrieval_preview_empty') }}
                </div>
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($results as $index => $result)
                        @php
                            $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
                            $provenance = is_array($result['provenance'] ?? null) ? $result['provenance'] : [];
                            $knowledgeBaseId = (int) ($metadata['knowledge_base_id'] ?? 0);
                            $documentName = trim((string) ($metadata['knowledge_base_name'] ?? ''));
                            $snippet = \Illuminate\Support\Str::limit(trim((string) ($result['content'] ?? '')), 240);
                            // provenance is only populated by KnowledgeRetrievalService::withEvidenceReferences(),
                            // which only runs in the multi-document merge branch of retrieveEvidenceFromMany().
                            // When exactly one candidate document is searched, that method short-circuits to
                            // retrieveEvidence(), whose rows carry the same source info under metadata instead.
                            // Fall back to metadata so source info renders on both code paths.
                            $sourceUrl = trim((string) ($provenance['source_url'] ?? $metadata['source_url'] ?? ''));
                            $sourceName = trim((string) ($provenance['source_name'] ?? $metadata['source_name'] ?? ''));
                        @endphp
                        <div class="px-6 py-5">
                            <div class="flex items-center justify-between gap-4">
                                <h4 class="text-sm font-semibold text-gray-900">
                                    @if ($knowledgeBaseId > 0)
                                        <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId]) }}" class="hover:text-orange-600">
                                            {{ $documentName !== '' ? $documentName : __('admin.knowledge_bases.retrieval_preview_unknown_document') }}
                                        </a>
                                    @else
                                        {{ $documentName !== '' ? $documentName : __('admin.knowledge_bases.retrieval_preview_unknown_document') }}
                                    @endif
                                </h4>
                                <span class="shrink-0 text-xs font-medium text-gray-400">
                                    {{ __('admin.knowledge_bases.retrieval_preview_rank', ['rank' => $index + 1]) }}
                                    &middot;
                                    {{ __('admin.knowledge_bases.retrieval_preview_score', ['score' => number_format((float) ($result['score'] ?? 0), 3)]) }}
                                </span>
                            </div>
                            @if (trim((string) ($result['chunk_title'] ?? '')) !== '')
                                <p class="mt-1 text-xs text-gray-500">{{ $result['chunk_title'] }}</p>
                            @endif
                            <p class="mt-2 text-sm text-gray-700">{{ $snippet }}</p>
                            @if ($sourceUrl !== '' || $sourceName !== '')
                                <p class="mt-2 text-xs text-gray-500">
                                    {{ __('admin.knowledge_bases.retrieval_preview_source') }}:
                                    @if ($sourceUrl !== '')
                                        <a href="{{ $sourceUrl }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline">{{ $sourceName !== '' ? $sourceName : $sourceUrl }}</a>
                                    @else
                                        {{ $sourceName }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
