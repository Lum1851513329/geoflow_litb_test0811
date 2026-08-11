@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div>
        <a href="{{ route('admin.content-briefs.strategy', $brief) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← {{ __('admin.content_strategy.actions.back_to_strategy') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.blueprint.title') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.blueprint.subtitle', ['topic' => $brief->topic, 'version' => $strategy->version]) }}</p>
    </div>
    <ol class="grid gap-2 sm:grid-cols-5" aria-label="{{ __('admin.content_strategy.wizard.label') }}">@foreach(__('admin.content_strategy.wizard.steps') as $number => $label)<li class="rounded-lg px-3 py-2 text-sm {{ (int) $number === 3 ? 'bg-blue-600 font-semibold text-white' : ((int) $number < 3 ? 'bg-blue-50 text-blue-700' : 'bg-white text-gray-500 shadow-sm') }}">{{ $number }}. {{ $label }}</li>@endforeach</ol>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.content_strategy.blueprint.editor_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.blueprint.editor_hint') }}</p>
        <form method="POST" action="{{ route('admin.content-briefs.blueprint.confirm', $brief) }}" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="strategy_version_id" value="{{ $strategy->id }}">
            <textarea required name="blueprint_json" rows="28" spellcheck="false" class="w-full rounded-md border-gray-300 font-mono text-xs leading-5">{{ old('blueprint_json', $blueprintJson) }}</textarea>
            <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.content_strategy.actions.confirm_blueprint') }}</button>
        </form>
    </section>
    @if($latestBlueprint)<p class="text-sm text-gray-500">{{ __('admin.content_strategy.blueprint.latest_version', ['version' => $latestBlueprint->version]) }}</p>@endif
</div>
@endsection
