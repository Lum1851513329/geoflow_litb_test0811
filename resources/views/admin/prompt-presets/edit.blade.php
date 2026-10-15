@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex items-start gap-3"><a href="{{ route('admin.prompt-presets.index') }}" class="mt-1 text-gray-400">←</a><div><h1 class="text-2xl font-bold">{{ $preset->display_name }}</h1><p class="font-mono text-xs text-gray-500">{{ $preset->preset_key }}</p></div></div>
    <nav class="flex flex-wrap gap-2">@foreach($versions as $version)<a href="{{ route('admin.prompt-presets.edit', [$preset, 'version_id' => $version->id]) }}" class="rounded-full px-3 py-1 text-sm {{ $selectedVersion?->id === $version->id ? 'bg-blue-600 text-white' : 'bg-white ring-1 ring-gray-200' }}">v{{ $version->version }} · {{ $version->lifecycle_status }}</a>@endforeach</nav>
    <div>{{ $versions->links() }}</div>

    @if($selectedVersion)
        <section class="rounded-lg bg-white p-6 shadow"><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">v{{ $selectedVersion->version }} · {{ $selectedVersion->lifecycle_status }}</h2><p class="mt-1 text-sm text-gray-500">{{ $selectedVersion->change_note }}</p></div><div class="flex flex-wrap gap-2">@if($selectedVersion->lifecycle_status !== \App\Models\PromptPresetVersion::STATUS_ARCHIVED && \Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('publishVersion', [$preset, $selectedVersion]))<form method="POST" action="{{ route('admin.prompt-presets.versions.publish', [$preset, $selectedVersion]) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="2000" class="rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_presets.fields.reason') }}"><button class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white">{{ $selectedVersion->lifecycle_status === \App\Models\PromptPresetVersion::STATUS_DEPRECATED ? __('admin.prompt_presets.actions.rollback_publish') : __('admin.prompt_presets.actions.publish') }}</button></form>@endif @if($selectedVersion->lifecycle_status !== \App\Models\PromptPresetVersion::STATUS_ARCHIVED && \Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('deprecateVersion', [$preset, $selectedVersion]))<form method="POST" action="{{ route('admin.prompt-presets.versions.deprecate', [$preset, $selectedVersion]) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="2000" class="rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_presets.fields.reason') }}"><button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white">{{ __('admin.prompt_presets.actions.deprecate') }}</button></form>@endif @if($selectedVersion->lifecycle_status !== \App\Models\PromptPresetVersion::STATUS_ARCHIVED && \Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('archiveVersion', [$preset, $selectedVersion]))<form method="POST" action="{{ route('admin.prompt-presets.versions.archive', [$preset, $selectedVersion]) }}" class="flex gap-2">@csrf<input name="reason" required maxlength="2000" class="rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_presets.fields.reason') }}"><button class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white">{{ __('admin.prompt_presets.actions.archive') }}</button></form>@endif</div></div>
            <div class="mt-5 grid gap-4 lg:grid-cols-3">@foreach(['matching_rules', 'generation_policy', 'conflict_policy'] as $field)<div><h3 class="text-sm font-semibold">{{ __('admin.prompt_presets.fields.'.$field) }}</h3><pre class="mt-2 overflow-auto rounded bg-gray-50 p-3 text-xs">{{ json_encode($selectedVersion->{$field} ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endforeach</div>
            <div class="mt-5"><h3 class="font-semibold">{{ __('admin.prompt_presets.fields.ordered_modules') }}</h3><ol class="mt-3 space-y-2">@foreach($selectedVersion->moduleVersions as $binding)<li class="flex items-center justify-between rounded border border-gray-200 px-4 py-3 text-sm"><span><strong>{{ $binding->sort_order }}.</strong> {{ $binding->promptModuleVersion?->module?->display_name }} · v{{ $binding->promptModuleVersion?->version }} · #{{ $binding->prompt_module_version_id }}</span><span>{{ $binding->is_required ? __('admin.prompt_presets.fields.required') : __('admin.prompt_presets.fields.optional') }}</span></li>@endforeach</ol></div>
        </section>

        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold">{{ __('admin.prompt_presets.usage.title') }}</h2><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-xs uppercase text-gray-500"><th class="py-2">{{ __('admin.prompt_presets.usage.run') }}</th><th>{{ __('admin.prompt_presets.fields.status') }}</th><th>{{ __('admin.prompt_presets.usage.article') }}</th></tr></thead><tbody>@forelse($usages as $run)<tr class="border-t"><td class="py-3">#{{ $run->id }}</td><td>{{ $run->status }}</td><td>@if($run->article)<a href="{{ route('admin.articles.edit', ['articleId' => $run->article->id]) }}" class="text-blue-600">{{ $run->article->title }}</a>@else—@endif</td></tr>@empty<tr><td colspan="3" class="py-6 text-center text-gray-500">{{ __('admin.prompt_presets.usage.empty') }}</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $usages->links() }}</div></section>
    @endif

    @if(\Illuminate\Support\Facades\Gate::forUser(auth('admin')->user())->allows('update', $preset))
        <section class="rounded-lg bg-white p-6 shadow"><h2 class="text-lg font-semibold">{{ __('admin.prompt_presets.version_create_title') }}</h2><p class="mt-1 text-sm text-gray-500">{{ __('admin.prompt_presets.version_create_hint') }}</p><form method="POST" action="{{ route('admin.prompt-presets.versions.store', $preset) }}" class="mt-5 grid gap-4 lg:grid-cols-2" data-preset-form data-module-version-options-url="{{ route('admin.prompt-module-version-options') }}">@csrf
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.preset_key') }}<input name="preset_key" value="{{ old('preset_key', $preset->preset_key) }}" required class="mt-1 w-full rounded-md border-gray-300"></label><label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.display_name') }}<input name="display_name" value="{{ old('display_name', $preset->display_name) }}" required class="mt-1 w-full rounded-md border-gray-300"></label><label class="lg:col-span-2 text-sm font-medium">{{ __('admin.prompt_presets.fields.description') }}<textarea name="description" rows="2" class="mt-1 w-full rounded-md border-gray-300">{{ old('description', $preset->description) }}</textarea></label>
            @foreach(['matching_rules', 'generation_policy', 'conflict_policy'] as $field)<label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.'.$field) }}<textarea name="{{ $field }}" rows="6" {{ $field !== 'conflict_policy' ? 'required' : '' }} class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old($field, json_encode($selectedVersion?->{$field} ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea></label>@endforeach<label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.blueprint_template') }}<textarea name="blueprint_template" rows="16" spellcheck="false" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('blueprint_template', $selectedVersion?->blueprint_template !== null ? json_encode($selectedVersion->blueprint_template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null) }}</textarea></label><label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.default_brief_fields') }}<textarea name="default_brief_fields" rows="6" spellcheck="false" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('default_brief_fields', $selectedVersion?->default_brief_fields !== null ? json_encode($selectedVersion->default_brief_fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null) }}</textarea></label><label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.change_note') }}<textarea name="change_note" rows="6" class="mt-1 w-full rounded-md border-gray-300">{{ old('change_note') }}</textarea></label>
            <div class="lg:col-span-2"><div class="flex flex-wrap items-end justify-between gap-3"><div><h3 class="font-semibold">{{ __('admin.prompt_presets.fields.ordered_modules') }}</h3><input type="search" data-module-version-search class="mt-2 rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_presets.fields.search_versions') }}"></div><button type="button" data-add-binding class="rounded-md border px-3 py-2 text-sm">{{ __('admin.prompt_presets.actions.add_module') }}</button></div><div data-bindings class="mt-3 space-y-2">@foreach($selectedVersion?->moduleVersions ?? [] as $index => $binding)<div data-binding-row class="grid gap-2 sm:grid-cols-[1fr_auto_auto]"><input type="hidden" name="module_versions[{{ $index }}][sort_order]" value="{{ $index + 1 }}"><select name="module_versions[{{ $index }}][prompt_module_version_id]" required class="rounded-md border-gray-300 text-sm">@foreach($moduleVersions as $version)<option value="{{ $version->id }}" @selected($binding->prompt_module_version_id === $version->id)>{{ $version->module?->display_name }} · v{{ $version->version }} · {{ $version->lifecycle_status }} · #{{ $version->id }}</option>@endforeach</select><span><input type="hidden" name="module_versions[{{ $index }}][is_required]" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" data-binding-required name="module_versions[{{ $index }}][is_required]" value="1" @checked($binding->is_required)>{{ __('admin.prompt_presets.fields.required') }}</label></span><div class="flex items-center gap-2"><button type="button" data-move-binding-up class="text-sm text-gray-600">{{ __('admin.prompt_presets.actions.move_up') }}</button><button type="button" data-move-binding-down class="text-sm text-gray-600">{{ __('admin.prompt_presets.actions.move_down') }}</button><button type="button" data-remove-binding class="text-sm text-red-600">{{ __('admin.prompt_presets.actions.remove') }}</button></div></div>@endforeach</div></div>
            <div class="flex gap-5 lg:col-span-2"><input type="hidden" name="is_enabled" value="0"><label class="inline-flex items-center gap-2"><input type="checkbox" name="is_enabled" value="1" @checked($preset->is_enabled)>{{ __('admin.prompt_presets.fields.enabled') }}</label>@if(auth('admin')->user()?->isSuperAdmin())<input type="hidden" name="is_system" value="0"><label class="inline-flex items-center gap-2"><input type="checkbox" name="is_system" value="1" @checked($preset->is_system)>{{ __('admin.prompt_presets.fields.system') }}</label>@endif</div>
            <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">{{ __('admin.prompt_presets.actions.create_version') }}</button></div>
        </form></section>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-preset-form]').forEach((form) => {
    const bindings = form.querySelector('[data-bindings]');
    form.querySelector('[data-add-binding]')?.addEventListener('click', () => {
        const row = bindings.querySelector('[data-binding-row]').cloneNode(true);
        row.querySelectorAll('select,input').forEach((input) => {
            if (input.type === 'checkbox') { input.checked = false; input.value = '1'; return; }
            input.value = input.type === 'hidden' ? '0' : '';
        });
        bindings.appendChild(row);
        renumber();
    });
    bindings.addEventListener('click', (event) => {
        const row = event.target.closest('[data-binding-row]');
        if (! row) return;
        if (event.target.matches('[data-remove-binding]') && bindings.children.length > 1) row.remove();
        if (event.target.matches('[data-move-binding-up]') && row.previousElementSibling) bindings.insertBefore(row, row.previousElementSibling);
        if (event.target.matches('[data-move-binding-down]') && row.nextElementSibling) bindings.insertBefore(row.nextElementSibling, row);
        renumber();
    });
    function renumber() {
        bindings.querySelectorAll('[data-binding-row]').forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((input) => input.name = input.name.replace(/module_versions\[\d+\]/, `module_versions[${index}]`));
            row.querySelector('[name$="[sort_order]"]').value = index + 1;
        });
    }
    let searchTimer;
    form.querySelector('[data-module-version-search]')?.addEventListener('input', (event) => {
        clearTimeout(searchTimer);
        const query = event.target.value.trim();
        if (query.length < 1) return;
        searchTimer = setTimeout(async () => {
            const url = new URL(form.dataset.moduleVersionOptionsUrl, window.location.origin);
            url.searchParams.set('q', query);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (! response.ok) return;
            const payload = await response.json();
            bindings.querySelectorAll('select[name$="[prompt_module_version_id]"]').forEach((select) => {
                payload.data.forEach((version) => {
                    if ([...select.options].some((option) => option.value === String(version.id))) return;
                    const label = `${version.display_name} · v${version.version} · ${version.lifecycle_status} · #${version.id}`;
                    select.add(new Option(label, version.id));
                });
            });
        }, 200);
    });
    renumber();
});
</script>
@endpush
