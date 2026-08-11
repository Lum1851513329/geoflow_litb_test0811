@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><a href="{{ route('admin.content-briefs.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← {{ __('admin.content_strategy.actions.back_to_briefs') }}</a><h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.generation.title') }}</h1><p class="mt-1 text-sm text-gray-600">{{ $brief->topic }} · {{ $brief->language_code }} / {{ $brief->market_code }}</p></div>
        <a href="{{ route('admin.content-briefs.blueprint', ['brief' => $brief, 'strategy_version_id' => $strategy->id]) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.content_strategy.actions.revise_blueprint') }}</a>
    </div>
    <ol class="grid gap-2 sm:grid-cols-5" aria-label="{{ __('admin.content_strategy.wizard.label') }}">@foreach(__('admin.content_strategy.wizard.steps') as $number => $label)<li class="rounded-lg px-3 py-2 text-sm {{ (int) $number === 4 ? 'bg-blue-600 font-semibold text-white' : ((int) $number < 4 ? 'bg-blue-50 text-blue-700' : 'bg-white text-gray-500 shadow-sm') }}">{{ $number }}. {{ $label }}</li>@endforeach</ol>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-lg bg-white p-5 shadow"><h2 class="font-semibold text-gray-900">{{ __('admin.content_strategy.generation.confirmed_strategy') }} v{{ $strategy->version }}</h2><dl class="mt-4 grid gap-3 text-sm"><div><dt class="text-gray-500">{{ __('admin.content_strategy.fields.primary_intent') }}</dt><dd class="font-medium text-gray-900">{{ data_get($strategy->strategy, 'primary_intent', '—') }}</dd></div><div><dt class="text-gray-500">{{ __('admin.content_strategy.fields.content_goal') }}</dt><dd class="text-gray-900">{{ data_get($strategy->strategy, 'content_goal', '—') }}</dd></div></dl></section>
        <section class="rounded-lg bg-white p-5 shadow"><h2 class="font-semibold text-gray-900">{{ __('admin.content_strategy.generation.confirmed_blueprint') }} v{{ $blueprint->version }}</h2><dl class="mt-4 grid gap-3 text-sm"><div><dt class="text-gray-500">{{ __('admin.content_strategy.fields.working_title') }}</dt><dd class="font-medium text-gray-900">{{ data_get($blueprint->blueprint, 'working_title', '—') }}</dd></div><div><dt class="text-gray-500">{{ __('admin.content_strategy.fields.target_length') }}</dt><dd class="text-gray-900">{{ data_get($blueprint->blueprint, 'target_length', '—') }}</dd></div></dl></section>
    </div>

    <section class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.generation.system_modules') }}</h2><p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.generation.system_modules_hint') }}</p></div>@if($selectedPreset)<span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{{ $selectedPreset->preset?->display_name }} · v{{ $selectedPreset->version }}</span>@endif</div>
        @if($moduleResolutionError)<div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $moduleResolutionError }}</div>@endif
        <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">{{ __('admin.content_strategy.fields.module') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.version') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.type') }}</th><th class="px-4 py-3">{{ __('admin.content_strategy.fields.requirement') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($resolvedModules as $module)<tr><td class="px-4 py-3"><div class="font-medium text-gray-900">{{ $module['display_name'] }}</div><div class="font-mono text-xs text-gray-500">{{ $module['module_key'] }}</div></td><td class="px-4 py-3">v{{ $module['version'] }} <span class="text-xs text-gray-400">#{{ $module['version_id'] }}</span></td><td class="px-4 py-3 text-gray-600">{{ $module['module_type'] }}</td><td class="px-4 py-3">{{ $module['required'] ? __('admin.content_strategy.module.required') : __('admin.content_strategy.module.optional') }}</td></tr>@empty<tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">{{ __('admin.content_strategy.generation.no_modules') }}</td></tr>@endforelse</tbody></table></div>

        <form method="POST" action="{{ route('admin.content-briefs.generations.store', $brief) }}" class="mt-6 space-y-5">
            @csrf
            <input type="hidden" name="strategy_version_id" value="{{ $strategy->id }}">
            <input type="hidden" name="blueprint_version_id" value="{{ $blueprint->id }}">
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', 'content-generation-'.str()->uuid()) }}">
            <details data-advanced-module-configuration class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-800">{{ __('admin.content_strategy.generation.advanced_configuration') }}</summary>
                <p class="mt-2 text-xs text-gray-500">{{ __('admin.content_strategy.generation.advanced_hint') }}</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.prompt_preset') }}<select required name="preset_version_id" class="mt-1 w-full rounded-md border-gray-300">@foreach($presets as $preset)<option value="{{ $preset->id }}" @selected((int) old('preset_version_id', $selectedPreset?->id) === (int) $preset->id)>{{ $preset->preset?->display_name }} · v{{ $preset->version }}</option>@endforeach</select></label>
                    <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.ai_model') }}<select required name="ai_model_id" class="mt-1 w-full rounded-md border-gray-300">@foreach($models as $model)<option value="{{ $model->id }}" @selected((int) old('ai_model_id', $selectedModel?->id) === (int) $model->id)>{{ $model->name }} · {{ $model->model_id }}</option>@endforeach</select></label>
                </div>
            </details>
            <button @disabled(!$selectedPreset || !$selectedModel || $moduleResolutionError) class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">{{ __('admin.content_strategy.actions.queue_generation') }}</button>
        </form>
    </section>

    <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.generation.previous_runs') }}</h2><div class="mt-4 space-y-2">@forelse($runs as $run)<a href="{{ route('admin.content-generations.show', $run) }}" class="flex items-center justify-between rounded-md border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50"><span class="font-medium text-blue-600">#{{ $run->id }}</span><span class="text-gray-600">{{ data_get(__('admin.content_strategy.statuses'), $run->status, $run->status) }} · {{ $run->created_at?->format('Y-m-d H:i') }}</span></a>@empty<p class="text-sm text-gray-500">{{ __('admin.content_strategy.generation.no_runs') }}</p>@endforelse</div></section>
</div>
@endsection
