@extends('admin.layouts.app')

@section('content')
@php($strategy = old('strategy', $suggestion->strategy))
<div class="space-y-6 px-4 sm:px-0">
    <div>
        <a href="{{ route('admin.content-briefs.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← {{ __('admin.content_strategy.actions.back_to_briefs') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.strategy.title') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ $brief->topic }}</p>
    </div>
    <ol class="grid gap-2 sm:grid-cols-5" aria-label="{{ __('admin.content_strategy.wizard.label') }}">@foreach(__('admin.content_strategy.wizard.steps') as $number => $label)<li class="rounded-lg px-3 py-2 text-sm {{ (int) $number === 2 ? 'bg-blue-600 font-semibold text-white' : ((int) $number < 2 ? 'bg-blue-50 text-blue-700' : 'bg-white text-gray-500 shadow-sm') }}">{{ $number }}. {{ $label }}</li>@endforeach</ol>

    <section class="rounded-lg border border-blue-100 bg-blue-50 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold text-blue-950">{{ __('admin.content_strategy.strategy.suggestion') }}</h2><span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-blue-700">{{ __('admin.content_strategy.strategy.confidence', ['value' => number_format($suggestion->confidence * 100, 0)]) }}</span></div>
        <p class="mt-2 text-sm text-blue-900">{{ __('admin.content_strategy.strategy.suggestion_hint') }}</p>
        @if($suggestion->evidence !== [])<details class="mt-3 text-sm text-blue-900"><summary class="cursor-pointer font-medium">{{ __('admin.content_strategy.strategy.evidence') }}</summary><pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded bg-white p-3 text-xs">{{ json_encode($suggestion->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
    </section>

    <form method="POST" action="{{ route('admin.content-briefs.strategy.confirm', $brief) }}" class="grid gap-5 rounded-lg bg-white p-6 shadow lg:grid-cols-2">
        @csrf
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.primary_intent') }}<input required name="strategy[primary_intent]" value="{{ data_get($strategy, 'primary_intent') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.decision_stage') }}<input name="strategy[decision_stage]" value="{{ data_get($strategy, 'decision_stage') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.content_type') }}<input name="strategy[content_type]" value="{{ data_get($strategy, 'content_type') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.cta_strength') }}<input name="strategy[cta_strength]" value="{{ data_get($strategy, 'cta_strength') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.content_goal') }}<textarea name="strategy[content_goal]" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ data_get($strategy, 'content_goal') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.secondary_intents') }}<textarea name="strategy[secondary_intents]" rows="4" class="mt-1 w-full rounded-md border-gray-300">{{ implode("\n", (array) data_get($strategy, 'secondary_intents', [])) }}</textarea></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.questions_to_answer') }}<textarea name="strategy[questions_to_answer]" rows="4" class="mt-1 w-full rounded-md border-gray-300">{{ implode("\n", (array) data_get($strategy, 'questions_to_answer', [])) }}</textarea></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.correction_reason') }}<textarea name="correction_reason" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('correction_reason') }}</textarea><span class="mt-1 block text-xs text-gray-500">{{ __('admin.content_strategy.strategy.correction_hint') }}</span></label>
        <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.content_strategy.actions.confirm_strategy') }}</button></div>
    </form>

    @if($latestStrategy)<p class="text-sm text-gray-500">{{ __('admin.content_strategy.strategy.latest_version', ['version' => $latestStrategy->version]) }}</p>@endif
</div>
@endsection
