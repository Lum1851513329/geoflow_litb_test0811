@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div>
        <a href="{{ route('admin.content-briefs.index') }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">← {{ __('admin.content_strategy.actions.back_to_briefs') }}</a>
        <h1 class="mt-2 text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.create.title') }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.create.subtitle') }}</p>
    </div>

    <ol class="grid gap-2 sm:grid-cols-5" aria-label="{{ __('admin.content_strategy.wizard.label') }}">
        @foreach(__('admin.content_strategy.wizard.steps') as $number => $label)
            <li class="rounded-lg px-3 py-2 text-sm {{ (int) $number === 1 ? 'bg-blue-600 font-semibold text-white' : 'bg-white text-gray-500 shadow-sm' }}">{{ $number }}. {{ $label }}</li>
        @endforeach
    </ol>

    <form method="POST" action="{{ route('admin.content-briefs.store') }}" class="grid gap-5 rounded-lg bg-white p-6 shadow lg:grid-cols-2">
        @csrf
        <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-4 lg:col-span-2">
            <label class="block text-sm font-medium text-gray-700">{{ __('admin.content_strategy.quick_create.label') }}</label>
            <select id="prompt_preset_id" name="prompt_preset_id" class="mt-1 w-full rounded-md border-gray-300">
                <option value="">{{ __('admin.content_strategy.quick_create.manual_option') }}</option>
                @foreach ($quickCreatePresets as $preset)
                    <option value="{{ $preset->id }}" @selected((string) old('prompt_preset_id') === (string) $preset->id)>{{ $preset->display_name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ __('admin.content_strategy.quick_create.hint') }}</p>
        </div>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.topic') }}<input required name="topic" value="{{ old('topic') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.market_code') }}<input id="market_code" required name="market_code" value="{{ old('market_code') }}" placeholder="US" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.language_code') }}<input id="language_code" required name="language_code" value="{{ old('language_code') }}" placeholder="en" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.audience') }}<textarea id="audience" required name="audience" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('audience') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.core_question') }}<textarea required name="core_question" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('core_question') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.business_goal') }}<textarea id="business_goal" required name="business_goal" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('business_goal') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.content_type') }}<input id="content_type" required name="content_type" value="{{ old('content_type', 'guide') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.planned_publish_at') }}<input type="datetime-local" name="planned_publish_at" value="{{ old('planned_publish_at') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.keyword_cluster') }}<select name="keyword_cluster_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">—</option>@foreach($keywordClusters as $cluster)<option value="{{ $cluster->id }}" @selected((string) old('keyword_cluster_id') === (string) $cluster->id)>{{ $cluster->name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.primary_keyword') }}<select name="primary_keyword_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">—</option>@foreach($keywords as $keyword)<option value="{{ $keyword->id }}" @selected((string) old('primary_keyword_id') === (string) $keyword->id)>{{ $keyword->keyword }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.category') }}<select name="category_id" class="mt-1 w-full rounded-md border-gray-300"><option value="">—</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.cta_strength') }}<select name="cta_strength" class="mt-1 w-full rounded-md border-gray-300">@foreach(['none', 'soft', 'medium', 'strong'] as $strength)<option value="{{ $strength }}" @selected(old('cta_strength', 'soft') === $strength)>{{ __('admin.content_strategy.cta.'.$strength) }}</option>@endforeach</select></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.target_url') }}<input type="url" name="target_url" value="{{ old('target_url') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.required_mentions') }}<textarea name="required_mentions" rows="4" class="mt-1 w-full rounded-md border-gray-300" placeholder="{{ __('admin.content_strategy.hints.one_per_line') }}">{{ old('required_mentions') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.prohibited_information') }}<textarea name="prohibited_information" rows="4" class="mt-1 w-full rounded-md border-gray-300" placeholder="{{ __('admin.content_strategy.hints.one_per_line') }}">{{ old('prohibited_information') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700 lg:col-span-2">{{ __('admin.content_strategy.fields.reference_urls') }}<textarea name="reference_urls" rows="4" class="mt-1 w-full rounded-md border-gray-300" placeholder="{{ __('admin.content_strategy.hints.one_per_line') }}">{{ old('reference_urls') }}</textarea></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.campaign_starts_at') }}<input type="datetime-local" name="campaign_starts_at" value="{{ old('campaign_starts_at') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <label class="text-sm font-medium text-gray-700">{{ __('admin.content_strategy.fields.campaign_ends_at') }}<input type="datetime-local" name="campaign_ends_at" value="{{ old('campaign_ends_at') }}" class="mt-1 w-full rounded-md border-gray-300"></label>
        <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.content_strategy.actions.continue_strategy') }}</button></div>
    </form>
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const presetSelect = document.getElementById('prompt_preset_id');
            const manualRequiredFields = [
                document.getElementById('market_code'),
                document.getElementById('language_code'),
                document.getElementById('audience'),
                document.getElementById('business_goal'),
                document.getElementById('content_type'),
            ].filter(Boolean);

            if (!presetSelect || manualRequiredFields.length === 0) {
                return;
            }

            function syncManualFieldsRequired() {
                const presetChosen = presetSelect.value !== '';
                manualRequiredFields.forEach(function (field) {
                    field.required = !presetChosen;
                });
            }

            presetSelect.addEventListener('change', syncManualFieldsRequired);
            syncManualFieldsRequired();
        });
    </script>
@endpush
@endsection
