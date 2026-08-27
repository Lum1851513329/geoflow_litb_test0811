@extends('admin.layouts.app')

@section('content')
@php
    $contextDigest = data_get($run->variables_snapshot, 'context_digest');
    $conflictDecisions = (array) data_get($run->variables_snapshot, 'conflict_decisions', []);
    $inputTokens = (int) $run->steps->sum('input_tokens');
    $outputTokens = (int) $run->steps->sum('output_tokens');
    $cost = $run->steps->sum(fn ($step) => (float) ($step->cost ?? 0));
@endphp
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><a href="{{ route('admin.content-briefs.show', $run->contentBrief) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← {{ __('admin.content_strategy.actions.back_to_generation') }}</a><h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.run.title', ['id' => $run->id]) }}</h1><p class="mt-1 text-sm text-gray-600">{{ $run->contentBrief->topic }}</p></div>
        <span class="rounded-full bg-gray-100 px-3 py-1.5 text-sm font-semibold text-gray-700">{{ data_get(__('admin.content_strategy.statuses'), $run->status, $run->status) }}</span>
    </div>
    <ol class="grid gap-2 sm:grid-cols-5" aria-label="{{ __('admin.content_strategy.wizard.label') }}">@foreach(__('admin.content_strategy.wizard.steps') as $number => $label)<li class="rounded-lg px-3 py-2 text-sm {{ (int) $number === 5 ? 'bg-blue-600 font-semibold text-white' : 'bg-blue-50 text-blue-700' }}">{{ $number }}. {{ $label }}</li>@endforeach</ol>

    <section class="grid gap-4 rounded-lg bg-white p-5 shadow sm:grid-cols-2 lg:grid-cols-4">
        <div><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.content_strategy.fields.preset') }}</div><div class="mt-1 font-medium text-gray-900">{{ $run->presetVersion?->preset?->display_name ?? '—' }} · v{{ $run->presetVersion?->version ?? '—' }}</div></div>
        <div><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.content_strategy.fields.model') }}</div><div class="mt-1 font-medium text-gray-900">{{ data_get($run->model_snapshot, 'name', $run->aiModel?->name ?? '—') }} · {{ data_get($run->model_snapshot, 'model_id', $run->aiModel?->model_id ?? '—') }}</div></div>
        <div><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.content_strategy.fields.token_usage') }}</div><div class="mt-1 font-medium text-gray-900">{{ number_format($inputTokens) }} + {{ number_format($outputTokens) }}</div></div>
        <div><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.content_strategy.fields.cost') }}</div><div class="mt-1 font-medium text-gray-900">{{ number_format($cost, 6) }}</div></div>
    </section>

    @if($run->error_message)<div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $run->error_message }}</div>@endif

    @if($run->evidencePreparation)
        @php($preparationResult = (array) ($run->evidencePreparation->result_json ?? []))
        <section class="rounded-lg border border-blue-200 bg-blue-50 p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg font-semibold text-blue-950">外部证据准备审计</h2><p class="mt-1 text-sm text-blue-800">本地优先；只有证据不足时才会抓取允许域名。</p></div>
                <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-blue-800">{{ data_get($preparationResult, 'state', $run->evidencePreparation->phase) }}</span>
            </div>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-blue-700">抓取页面</dt><dd class="font-semibold text-blue-950">{{ $run->evidencePreparation->pages_fetched }} / 6</dd></div>
                <div><dt class="text-blue-700">搜索轮次</dt><dd class="font-semibold text-blue-950">{{ $run->evidencePreparation->search_rounds }} / 2</dd></div>
                <div><dt class="text-blue-700">未验证核心事实</dt><dd class="font-semibold text-blue-950">{{ count((array) data_get($preparationResult, 'missing_core', [])) }}</dd></div>
                <div><dt class="text-blue-700">禁止写入的非核心事实</dt><dd class="font-semibold text-blue-950">{{ count((array) data_get($preparationResult, 'prohibited_claims', [])) }}</dd></div>
            </dl>
            @if((array) $run->evidencePreparation->attempt_log !== [])
                <details class="mt-4 rounded-md bg-white p-4"><summary class="cursor-pointer text-sm font-semibold text-gray-800">查看搜索/抓取记录</summary><div class="mt-3 space-y-2">@foreach((array) $run->evidencePreparation->attempt_log as $attempt)<div class="break-all font-mono text-xs text-gray-700">{{ data_get($attempt, 'type') }} · {{ data_get($attempt, 'status') }} · {{ data_get($attempt, 'url', implode(' | ', (array) data_get($attempt, 'queries', []))) }}</div>@endforeach</div></details>
            @endif
        </section>
    @endif

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.modules') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.run.modules_hint') }}</p>
        <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">{{ __('admin.content_strategy.fields.module') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.version') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.requirement') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($run->modules as $module)<tr><td class="px-4 py-3"><div class="font-medium text-gray-900">{{ data_get($module->module_snapshot, 'display_name', $module->promptModuleVersion?->module?->display_name ?? data_get($module->module_snapshot, 'module_key', '—')) }}</div><div class="font-mono text-xs text-gray-500">{{ data_get($module->module_snapshot, 'module_key', $module->promptModuleVersion?->module?->module_key) }}</div></td><td class="px-4 py-3">v{{ data_get($module->module_snapshot, 'version', $module->promptModuleVersion?->version ?? '—') }} <span class="text-xs text-gray-400">#{{ $module->prompt_module_version_id }}</span></td><td class="px-4 py-3">{{ $module->is_required ? __('admin.content_strategy.module.required') : __('admin.content_strategy.module.optional') }}</td></tr>@empty<tr><td colspan="3" class="px-4 py-8 text-center text-gray-500">{{ __('admin.content_strategy.run.trace_pending') }}</td></tr>@endforelse</tbody></table></div>
        <div class="mt-5 rounded-md bg-gray-50 p-4"><div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('admin.content_strategy.fields.context_digest') }}</div><div class="mt-1 break-all font-mono text-xs text-gray-800">{{ $contextDigest ?: __('admin.content_strategy.run.trace_pending') }}</div></div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.conflicts') }}</h2><div class="mt-4 space-y-3">@forelse($conflictDecisions as $decision)<article class="rounded-md border border-gray-200 p-4 text-sm"><div class="font-mono text-xs font-semibold text-blue-700">{{ data_get($decision, 'code', 'decision') }}</div><p class="mt-2 text-gray-800">{{ data_get($decision, 'reason', '—') }}</p><dl class="mt-2 grid gap-1 text-xs text-gray-500"><div>{{ __('admin.content_strategy.fields.winner') }}: {{ data_get($decision, 'winner', '—') }}</div><div>{{ __('admin.content_strategy.fields.loser') }}: {{ data_get($decision, 'loser', '—') }}</div></dl></article>@empty<p class="text-sm text-gray-500">{{ __('admin.content_strategy.run.no_conflicts') }}</p>@endforelse</div></section>
        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.evidence') }}</h2><div class="mt-4 space-y-3">@forelse($run->evidence as $evidence)@php
            $sourceUrl = trim((string) data_get($evidence->evidence_snapshot, 'source_url', ''));
            $sourceScheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
            $isSafeSourceUrl = $sourceUrl !== ''
                && filter_var($sourceUrl, FILTER_VALIDATE_URL) !== false
                && in_array($sourceScheme, ['http', 'https'], true);
        @endphp<article class="rounded-md border border-gray-200 p-4 text-sm"><div class="flex items-center justify-between gap-3"><div class="font-medium text-gray-900">{{ data_get($evidence->evidence_snapshot, 'title', $evidence->evidence_key) }}</div>@if($evidence->is_required)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">{{ __('admin.content_strategy.module.required') }}</span>@endif</div><div class="mt-1 font-mono text-xs text-gray-500">{{ $evidence->evidence_key }}</div>@if(data_get($evidence->evidence_snapshot, 'content'))<p class="mt-2 text-gray-700">{{ data_get($evidence->evidence_snapshot, 'content') }}</p>@endif @if($isSafeSourceUrl)<a class="mt-2 block break-all text-xs text-blue-600 hover:text-blue-700" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceUrl }}</a>@elseif($sourceUrl !== '')<span class="mt-2 block break-all text-xs text-gray-500">{{ $sourceUrl }}</span>@endif</article>@empty<p class="text-sm text-gray-500">{{ __('admin.content_strategy.run.no_evidence') }}</p>@endforelse</div></section>
    </div>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.quality') }}</h2>
        <div class="mt-4 space-y-4">@forelse($run->qualityReports as $report)<article class="rounded-lg border {{ $report->passed ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }} p-4"><div class="flex flex-wrap items-center justify-between gap-3"><div class="font-semibold text-gray-900">{{ data_get(__('admin.content_strategy.quality_types'), $report->report_type, $report->report_type) }} · {{ data_get(__('admin.content_strategy.quality_statuses'), $report->status, $report->status) }}</div>@if($report->score !== null)<span class="text-sm font-semibold text-gray-700">{{ __('admin.content_strategy.fields.score') }}: {{ $report->score }}</span>@endif</div><div class="mt-3 space-y-2">@foreach((array) $report->issues as $issue)<div class="rounded bg-white/80 p-3 text-sm"><div class="font-mono text-xs font-semibold text-amber-800">{{ data_get($issue, 'code', 'issue') }}</div><p class="mt-1 text-gray-800">{{ data_get($issue, 'message', '—') }}</p>@if(data_get($issue, 'repair_instruction'))<p class="mt-1 text-xs text-gray-600">{{ data_get($issue, 'repair_instruction') }}</p>@endif</div>@endforeach</div></article>@empty<p class="text-sm text-gray-500">{{ __('admin.content_strategy.run.no_quality_reports') }}</p>@endforelse</div>
    </section>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.revision_history') }}</h2>
        <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">#</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.step') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.status') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.token_usage') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.cost') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.completed_at') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($run->steps as $step)<tr><td class="px-4 py-3">{{ $step->sequence }}</td><td class="px-4 py-3 text-xs">{{ data_get(__('admin.content_strategy.step_types'), $step->step_type, $step->step_type) }}</td><td class="px-4 py-3">{{ data_get(__('admin.content_strategy.step_statuses'), $step->status, $step->status) }}</td><td class="px-4 py-3">{{ number_format((int) $step->input_tokens) }} + {{ number_format((int) $step->output_tokens) }}</td><td class="px-4 py-3">{{ $step->cost ?? '—' }}</td><td class="px-4 py-3 text-gray-500">{{ $step->completed_at?->format('Y-m-d H:i:s') ?? '—' }}@if($step->error_message)<div class="mt-1 text-xs text-red-600">{{ $step->error_message }}</div>@endif</td></tr>@empty<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">{{ __('admin.content_strategy.run.trace_pending') }}</td></tr>@endforelse</tbody></table></div>
    </section>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.compiled_prompt') }}</h2>
        @if($canViewCompiledPrompt)
            <pre class="mt-4 max-h-[36rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-5 text-xs leading-5 text-gray-100">{{ $run->compiled_prompt ?? __('admin.content_strategy.run.trace_pending') }}</pre>
        @else
            <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">{{ __('admin.content_strategy.run.prompt_restricted') }}</div>
        @endif
    </section>

    @if($run->raw_output)
        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.run.generated_output') }}</h2><pre class="mt-4 max-h-[40rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-5 text-sm leading-6 text-gray-800">{{ $run->raw_output }}</pre></section>
    @endif

    @if($run->status === \App\Models\ContentGenerationRun::STATUS_READY_FOR_REVIEW)
        <section class="rounded-lg border border-green-200 bg-green-50 p-6"><h2 class="text-lg font-semibold text-green-950">{{ __('admin.content_strategy.run.adopt_title') }}</h2><p class="mt-1 text-sm text-green-800">{{ __('admin.content_strategy.run.adopt_hint') }}</p><form method="POST" action="{{ route('admin.content-generations.adopt', $run) }}" class="mt-5 grid gap-4 lg:grid-cols-2">@csrf<label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.article_title') }}<input required name="title" value="{{ old('title', data_get($run->blueprintVersion?->blueprint, 'working_title', $run->contentBrief->topic)) }}" class="mt-1 w-full rounded-md border-gray-300"></label><label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.author') }}<select required name="author_id" class="mt-1 w-full rounded-md border-gray-300">@foreach($authors as $author)<option value="{{ $author->id }}" @selected((int) old('author_id') === (int) $author->id)>{{ $author->name }}</option>@endforeach</select></label><label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.category') }}<select required name="category_id" class="mt-1 w-full rounded-md border-gray-300">@foreach($categories as $category)<option value="{{ $category->id }}" @selected((int) old('category_id', $run->contentBrief->category_id) === (int) $category->id)>{{ $category->name }}</option>@endforeach</select></label><label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.article_content') }}<textarea name="content" rows="18" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('content', $run->raw_output) }}</textarea></label><label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.excerpt') }}<textarea name="excerpt" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('excerpt') }}</textarea></label><label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.keywords') }}<input name="keywords" value="{{ old('keywords') }}" class="mt-1 w-full rounded-md border-gray-300"></label><label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.meta_description') }}<input name="meta_description" value="{{ old('meta_description') }}" class="mt-1 w-full rounded-md border-gray-300"></label><div class="lg:col-span-2"><button class="rounded-md bg-green-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-green-800">{{ __('admin.content_strategy.actions.adopt_article') }}</button></div></form></section>
    @elseif($run->article)
        <section class="rounded-lg border border-blue-200 bg-blue-50 p-5"><p class="text-sm text-blue-900">{{ __('admin.content_strategy.run.adopted_article') }}</p><a href="{{ route('admin.articles.edit', $run->article->id) }}" class="mt-2 inline-block font-semibold text-blue-700 hover:text-blue-800">{{ $run->article->title }} →</a></section>
    @endif
</div>
@endsection
