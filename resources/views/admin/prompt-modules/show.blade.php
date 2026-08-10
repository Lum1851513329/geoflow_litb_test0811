@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3"><a href="{{ route('admin.prompt-modules.index') }}" class="mt-1 text-gray-400 hover:text-gray-700">←</a><div><h1 class="text-2xl font-bold text-gray-900">{{ $module->display_name }}</h1><p class="mt-1 font-mono text-xs text-gray-500">{{ $module->module_key }} · {{ $module->module_type }}</p></div></div>
        <a href="{{ route('admin.prompt-presets.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.actions.manage_presets') }}</a>
    </div>

    <nav class="flex flex-wrap gap-2">
        @foreach($versions as $version)
            <a href="{{ route('admin.prompt-modules.show', [$module, 'version_id' => $version->id]) }}" class="rounded-full px-3 py-1 text-sm {{ $selectedVersion?->id === $version->id ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 ring-1 ring-gray-200' }}">v{{ $version->version }} · {{ $version->lifecycle_status }}</a>
        @endforeach
    </nav>

    @if($selectedVersion)
        <section class="overflow-hidden rounded-lg bg-white shadow">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4"><div><h2 class="font-semibold text-gray-900">v{{ $selectedVersion->version }} · {{ $selectedVersion->lifecycle_status }}</h2><p class="mt-1 text-xs text-gray-500">SHA-256 {{ $selectedVersion->content_sha256 }}</p></div><div class="flex flex-wrap gap-2">
                @if($selectedVersion->lifecycle_status !== \App\Models\PromptModuleVersion::STATUS_ARCHIVED && \Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('publishVersion', [$module, $selectedVersion]))
                    <form method="POST" action="{{ route('admin.prompt-modules.versions.publish', [$module, $selectedVersion]) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="2000" class="rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_modules.fields.reason') }}"><button class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white">{{ $selectedVersion->lifecycle_status === \App\Models\PromptModuleVersion::STATUS_DEPRECATED ? __('admin.prompt_modules.actions.rollback_publish') : __('admin.prompt_modules.actions.publish') }}</button></form>
                    <form method="POST" action="{{ route('admin.prompt-modules.versions.archive', [$module, $selectedVersion]) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="2000" class="rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_modules.fields.reason') }}"><button class="rounded-md border border-red-300 px-3 py-2 text-sm font-semibold text-red-700">{{ __('admin.prompt_modules.actions.archive') }}</button></form>
                @endif
            </div></div>
            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap p-6 text-sm leading-6 text-gray-800">{{ $selectedVersion->content }}</pre>
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <div class="flex flex-wrap items-end justify-between gap-4"><div><h2 class="text-lg font-semibold">{{ __('admin.prompt_modules.diff.title') }}</h2><p class="text-sm text-gray-500">{{ __('admin.prompt_modules.diff.subtitle') }}</p></div><form method="GET" class="flex items-end gap-2"><input type="hidden" name="version_id" value="{{ $selectedVersion->id }}"><label class="text-xs text-gray-600">{{ __('admin.prompt_modules.diff.compare_to') }}<select name="compare_to" class="mt-1 rounded-md border-gray-300 text-sm">@foreach($versions->where('id', '!=', $selectedVersion->id) as $version)<option value="{{ $version->id }}" @selected($compareVersion?->id === $version->id)>v{{ $version->version }}</option>@endforeach</select></label><button class="rounded-md bg-gray-900 px-3 py-2 text-sm text-white">{{ __('admin.prompt_modules.actions.compare') }}</button></form></div>
            <div class="mt-4 overflow-auto rounded-md bg-gray-950 p-4 font-mono text-xs text-gray-200">
                @forelse($diffLines as $line)<div class="whitespace-pre-wrap {{ $line['kind'] === 'added' ? 'bg-green-950 text-green-200' : ($line['kind'] === 'removed' ? 'bg-red-950 text-red-200' : '') }}"><span class="inline-block w-5 select-none opacity-70">{{ $line['kind'] === 'added' ? '+' : ($line['kind'] === 'removed' ? '-' : ' ') }}</span>{{ $line['line'] }}</div>@empty<div class="text-gray-400">{{ __('admin.prompt_modules.diff.empty') }}</div>@endforelse
            </div>
        </section>

        <section class="rounded-lg bg-white p-6 shadow">
            <h2 class="text-lg font-semibold">{{ __('admin.prompt_modules.usage.title') }}</h2><p class="mt-1 text-sm text-gray-500">{{ __('admin.prompt_modules.usage.subtitle') }}</p>
            <div class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-2">{{ __('admin.prompt_modules.usage.run') }}</th><th>{{ __('admin.prompt_modules.fields.status') }}</th><th>{{ __('admin.prompt_modules.usage.article') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($usages as $usage)<tr><td class="py-3">#{{ $usage->content_generation_run_id }}</td><td>{{ $usage->run?->status }}</td><td>@if($usage->run?->article)<a class="text-blue-600" href="{{ route('admin.articles.edit', ['articleId' => $usage->run->article->id]) }}">{{ $usage->run->article->title }}</a>@else—@endif</td></tr>@empty<tr><td colspan="3" class="py-6 text-center text-gray-500">{{ __('admin.prompt_modules.usage.empty') }}</td></tr>@endforelse</tbody></table></div>
            <div class="mt-4">{{ $usages->links() }}</div>
        </section>
    @endif

    @if(\Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('createVersion', $module))
        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold">{{ __('admin.prompt_modules.version_create_title') }}</h2><form method="POST" action="{{ route('admin.prompt-modules.versions.store', $module) }}" class="mt-4 grid gap-4 lg:grid-cols-2">@csrf
            <label class="lg:col-span-2 text-sm font-medium">{{ __('admin.prompt_modules.fields.content') }}<textarea name="content" rows="10" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('content', $selectedVersion?->content) }}</textarea></label>
            @foreach(['variable_contract', 'output_contract', 'parameter_defaults'] as $field)<label class="text-sm font-medium">{{ __('admin.prompt_modules.fields.'.$field) }}<textarea name="{{ $field }}" rows="6" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old($field, json_encode($selectedVersion?->{$field} ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea></label>@endforeach
            <label class="text-sm font-medium">{{ __('admin.prompt_modules.fields.change_note') }}<textarea name="change_note" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('change_note') }}</textarea></label>
            <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">{{ __('admin.prompt_modules.actions.create_version') }}</button></div>
        </form></section>
    @endif
</div>
@endsection
