@php
    $scopeValues = $scopeValues ?? [];
    $scopeMode = old('knowledge_mode', $scopeValues['knowledge_mode'] ?? 'brand_auto');
@endphp
<fieldset data-knowledge-scope class="grid gap-3 rounded-lg border border-gray-200 p-4 lg:col-span-3">
    <legend class="px-1 font-semibold">Knowledge evidence scope</legend>
    <label>Knowledge mode
        <select name="knowledge_mode" class="mt-1 block w-full rounded-md border-gray-300">
            <option value="brand_auto" @selected($scopeMode === 'brand_auto')>Automatic brand evidence</option>
            <option value="legacy_manual" @selected($scopeMode === 'legacy_manual')>Legacy manual libraries</option>
        </select>
    </label>
    <div data-brand-scope-fields @if($scopeMode !== 'brand_auto') hidden @endif class="grid gap-3 sm:grid-cols-3">
        <label>Brand<select name="knowledge_brand_id" class="mt-1 block w-full rounded-md border-gray-300"><option value="">Select brand</option>@foreach($knowledgeScopeOptions['brands'] as $brand)<option value="{{ $brand->id }}" @selected((string) old('knowledge_brand_id', $scopeValues['knowledge_brand_id'] ?? '') === (string) $brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
        <label>Source site (not publishing channel)<select name="knowledge_site_id" class="mt-1 block w-full rounded-md border-gray-300"><option value="">Select source site</option>@foreach($knowledgeScopeOptions['sites'] as $site)<option value="{{ $site->id }}" data-brand="{{ $site->brand_id }}" @selected((string) old('knowledge_site_id', $scopeValues['knowledge_site_id'] ?? '') === (string) $site->id)>{{ $site->canonical_host }}</option>@endforeach</select></label>
        <label>Directory (includes descendants)<select name="knowledge_directory_id" class="mt-1 block w-full rounded-md border-gray-300"><option value="">All brand directories</option>@foreach($knowledgeScopeOptions['directories'] as $directory)<option value="{{ $directory->id }}" data-brand="{{ $directory->brand_id }}" @selected((string) old('knowledge_directory_id', $scopeValues['knowledge_directory_id'] ?? '') === (string) $directory->id)>{{ $directory->path }} — {{ $directory->name }}</option>@endforeach</select></label>
        <p class="text-sm text-gray-600 sm:col-span-3">Only reviewed, active, generation-eligible evidence in this brand, source site and language is retrieved. Reference URLs are not used as unreviewed evidence in brand mode. Generation retrieves current evidence automatically; preview is optional.</p>
    </div>
    @foreach(['knowledge_mode', 'knowledge_brand_id', 'knowledge_site_id', 'knowledge_directory_id'] as $field)
        @error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @endforeach
</fieldset>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const scope = document.querySelector('[data-knowledge-scope]');
    if (!scope) return;
    const form = scope.closest('form');
    const mode = scope.querySelector('[name="knowledge_mode"]');
    const brand = scope.querySelector('[name="knowledge_brand_id"]');
    const site = scope.querySelector('[name="knowledge_site_id"]');
    const directory = scope.querySelector('[name="knowledge_directory_id"]');
    function sync() {
        const automatic = mode.value === 'brand_auto';
        scope.querySelector('[data-brand-scope-fields]').hidden = !automatic;
        [brand, site, directory].forEach(field => field.disabled = !automatic);
        [site, directory].forEach(field => {
            [...field.options].forEach(option => {
                const foreign = option.value !== '' && option.dataset.brand !== brand.value;
                option.hidden = foreign;
                option.disabled = foreign;
            });
            if (field.selectedOptions[0]?.disabled) field.value = '';
        });
        const sites = [...site.options].filter(option => option.value && !option.disabled);
        if (!site.value && sites.length === 1) site.value = sites[0].value;
        form.querySelectorAll('[data-legacy-knowledge]').forEach(section => section.hidden = automatic);
        form.querySelectorAll('[name="knowledge_base_ids[]"]').forEach(input => input.disabled = automatic);
    }
    scope.addEventListener('change', event => {
        if (event.target === brand) { site.value = ''; directory.value = ''; }
        form.querySelectorAll('[name="knowledge_chunk_ids[]"], [name="excluded_knowledge_chunk_ids[]"], [name="knowledge_base_ids[]"]').forEach(input => {
            if (input.type === 'checkbox') input.checked = false;
            else input.remove();
        });
        form.querySelectorAll('[data-knowledge-preview-results]').forEach(node => node.replaceChildren());
        sync();
    });
    sync();
});
</script>
@endpush
