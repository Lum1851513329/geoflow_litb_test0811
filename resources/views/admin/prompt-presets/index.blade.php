@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h1 class="text-2xl font-bold text-gray-900">{{ __('admin.prompt_presets.title') }}</h1><p class="mt-1 text-sm text-gray-600">{{ __('admin.prompt_presets.subtitle') }}</p></div><a href="{{ route('admin.prompt-modules.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('admin.prompt_presets.actions.manage_modules') }}</a></div>

    <form method="GET" class="flex gap-3 rounded-lg bg-white p-4 shadow"><input name="q" value="{{ request('q') }}" class="min-w-0 flex-1 rounded-md border-gray-300" placeholder="{{ __('admin.prompt_presets.filters.search') }}"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm text-white">{{ __('admin.prompt_presets.actions.filter') }}</button></form>

    <section class="overflow-hidden rounded-lg bg-white shadow"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm"><thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500"><tr><th class="px-5 py-3">{{ __('admin.prompt_presets.fields.preset') }}</th><th class="px-5 py-3">{{ __('admin.prompt_presets.fields.current_version') }}</th><th class="px-5 py-3">{{ __('admin.prompt_presets.fields.status') }}</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($presets as $preset)<tr><td class="px-5 py-4"><a class="font-semibold text-blue-600" href="{{ route('admin.prompt-presets.edit', $preset) }}">{{ $preset->display_name }}</a><div class="font-mono text-xs text-gray-500">{{ $preset->preset_key }}</div></td><td class="px-5 py-4">{{ $preset->currentVersion ? 'v'.$preset->currentVersion->version : '—' }}</td><td class="px-5 py-4">{{ $preset->currentVersion?->lifecycle_status ?? '—' }}</td></tr>@empty<tr><td colspan="3" class="px-5 py-10 text-center text-gray-500">{{ __('admin.prompt_presets.empty') }}</td></tr>@endforelse</tbody></table></div><div class="border-t px-5 py-4">{{ $presets->links() }}</div></section>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold">{{ __('admin.prompt_presets.create_title') }}</h2>
        <form method="POST" action="{{ route('admin.prompt-presets.store') }}" class="mt-5 grid gap-4 lg:grid-cols-2" data-preset-form data-module-version-options-url="{{ route('admin.prompt-module-version-options') }}">@csrf
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.preset_key') }}<input name="preset_key" value="{{ old('preset_key') }}" required class="mt-1 w-full rounded-md border-gray-300"></label>
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.display_name') }}<input name="display_name" value="{{ old('display_name') }}" required class="mt-1 w-full rounded-md border-gray-300"></label>
            <label class="lg:col-span-2 text-sm font-medium">{{ __('admin.prompt_presets.fields.description') }}<textarea name="description" rows="2" class="mt-1 w-full rounded-md border-gray-300">{{ old('description') }}</textarea></label>
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.matching_rules') }}<textarea name="matching_rules" rows="6" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('matching_rules', '{}') }}</textarea></label>
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.generation_policy') }}<textarea name="generation_policy" rows="6" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('generation_policy', '{}') }}</textarea></label>
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.conflict_policy') }}<textarea name="conflict_policy" rows="5" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('conflict_policy', '{}') }}</textarea></label>
            <label class="text-sm font-medium">{{ __('admin.prompt_presets.fields.change_note') }}<textarea name="change_note" rows="5" class="mt-1 w-full rounded-md border-gray-300">{{ old('change_note') }}</textarea></label>
            <div class="lg:col-span-2"><div class="flex flex-wrap items-end justify-between gap-3"><div><h3 class="font-semibold">{{ __('admin.prompt_presets.fields.ordered_modules') }}</h3><p class="text-xs text-gray-500">{{ __('admin.prompt_presets.order_hint') }}</p><input type="search" data-module-version-search class="mt-2 rounded-md border-gray-300 text-sm" placeholder="{{ __('admin.prompt_presets.fields.search_versions') }}"></div><button type="button" data-add-binding class="rounded-md border px-3 py-2 text-sm">{{ __('admin.prompt_presets.actions.add_module') }}</button></div><div data-bindings class="mt-3 space-y-2"><div data-binding-row class="grid gap-2 sm:grid-cols-[1fr_auto_auto]"><input type="hidden" name="module_versions[0][sort_order]" value="1"><select name="module_versions[0][prompt_module_version_id]" required class="rounded-md border-gray-300 text-sm"><option value="">{{ __('admin.prompt_presets.fields.choose_version') }}</option>@foreach($moduleVersions as $version)<option value="{{ $version->id }}">{{ $version->module?->display_name }} · v{{ $version->version }} · {{ $version->lifecycle_status }} · #{{ $version->id }}</option>@endforeach</select><input type="hidden" name="module_versions[0][is_required]" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" data-binding-required name="module_versions[0][is_required]" value="1">{{ __('admin.prompt_presets.fields.required') }}</label><div class="flex items-center gap-2"><button type="button" data-move-binding-up class="text-sm text-gray-600">{{ __('admin.prompt_presets.actions.move_up') }}</button><button type="button" data-move-binding-down class="text-sm text-gray-600">{{ __('admin.prompt_presets.actions.move_down') }}</button><button type="button" data-remove-binding class="text-sm text-red-600">{{ __('admin.prompt_presets.actions.remove') }}</button></div></div></div></div>
            <div class="flex flex-wrap gap-5 lg:col-span-2"><input type="hidden" name="is_enabled" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_enabled" value="1" checked>{{ __('admin.prompt_presets.fields.enabled') }}</label>@if(auth('admin')->user()?->isSuperAdmin())<input type="hidden" name="is_system" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_system" value="1">{{ __('admin.prompt_presets.fields.system') }}</label>@endif</div>
            <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">{{ __('admin.prompt_presets.actions.create') }}</button></div>
        </form>
    </section>
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
