@php
    $oldIncludes = collect(old('knowledge_chunk_ids', []))->filter(fn ($id) => (is_int($id) || is_string($id)) && ctype_digit((string) $id))->all();
    $oldExcludes = collect(old('excluded_knowledge_chunk_ids', []))->filter(fn ($id) => (is_int($id) || is_string($id)) && ctype_digit((string) $id))->all();
@endphp
<fieldset data-brand-preview class="space-y-3 rounded-lg border border-gray-200 p-4">
    <legend class="px-1 font-semibold">Automatic brand evidence</legend>
    <p class="text-sm">Brand: {{ $knowledgeScopeOptions['brands']->firstWhere('id', $brief->knowledge_brand_id)?->name }} · Source site: {{ $knowledgeScopeOptions['sites']->firstWhere('id', $brief->knowledge_site_id)?->canonical_host }} · Directory: {{ $knowledgeScopeOptions['directories']->firstWhere('id', $brief->knowledge_directory_id)?->path ?? 'All' }} · Language: {{ $brief->language_code }}</p>
    <p class="text-sm text-gray-600">Reference URLs are not used as unreviewed evidence in brand mode. Preview is read-only. Generation re-runs retrieval; no preview is required. Manual additions and exclusions must remain within this reviewed scope.</p>
    <details class="rounded border p-3">
        <summary class="cursor-pointer font-medium">Add reviewed evidence from this scope</summary>
        <p class="text-sm text-gray-600">Optional: checked chunks supplement automatic retrieval. Exclusions take precedence.</p>
        <div class="mt-2 grid max-h-60 gap-2 overflow-y-auto sm:grid-cols-2">
            @forelse($manualKnowledgeChoices as $choice)
                <label class="flex gap-2 rounded border p-2 text-sm"><input type="checkbox" name="knowledge_chunk_ids[]" value="{{ $choice->id }}" @checked(in_array($choice->id, $oldIncludes))><span>{{ $choice->chunk_title ?: $choice->section_path ?: '#'.$choice->id }}<span class="block text-xs text-gray-500">{{ $choice->knowledgeBase?->name }} · #{{ $choice->id }}</span></span></label>
            @empty<p>No eligible chunks in this scope.</p>@endforelse
        </div>
        @if($manualKnowledgeChoicesTruncated)<p class="text-sm text-amber-700">Showing the first 100 eligible chunks. Use an advanced chunk ID for other reviewed evidence in the same scope.</p>@endif
    </details>
    <div data-manual-additions>
        @foreach(array_diff($oldIncludes, $manualKnowledgeChoices->pluck('id')->all()) as $id)<label class="block">Add chunk ID <input type="number" min="1" name="knowledge_chunk_ids[]" value="{{ $id }}"><button type="button" onclick="this.parentElement.remove()">Remove</button></label>@endforeach
    </div>
    <button type="button" data-add-chunk class="rounded border px-3 py-1">Advanced: add a scoped chunk ID</button>
    <div data-exclusion-inputs>
        @foreach($oldExcludes as $id)<label class="block">Exclude chunk ID <input type="number" min="1" name="excluded_knowledge_chunk_ids[]" value="{{ $id }}"><button type="button" onclick="this.parentElement.remove()">Remove</button></label>@endforeach
    </div>
    <button type="button" data-refresh-preview class="rounded bg-blue-50 px-3 py-1 text-blue-700">Re-run evidence preview</button>
    <p data-preview-error role="alert" class="text-sm text-red-600">{{ $knowledgePreviewError }}</p>
    @error('knowledge_chunk_ids')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @error('excluded_knowledge_chunk_ids')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    <div data-knowledge-preview-results class="space-y-3">
        @foreach($knowledgePreview['warnings'] ?? [] as $warning)<p class="text-sm text-amber-700">{{ $warning }}</p>@endforeach
        @foreach($knowledgePreview['evidence'] ?? [] as $item)
            <article class="rounded border p-3 text-sm">
                <p class="font-semibold">{{ $item['chunk_title'] ?: $item['knowledge_base_name'] }} · #{{ $item['chunk_id'] }} · {{ $item['selection_method'] }}</p>
                <p class="whitespace-pre-wrap">{{ $item['content'] }}</p>
                <p>{{ $item['source_name'] ?? '' }} · {{ $item['version_label'] ?? '' }} · {{ $item['review_status'] }} · {{ $item['authority_level'] ?? '' }}</p>
                @if(preg_match('~^https?://~i', (string) ($item['source_url'] ?? '')))<a href="{{ $item['source_url'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600">{{ $item['source_url'] }}</a>@endif
                <label class="block"><input type="checkbox" data-exclude-chunk="{{ $item['chunk_id'] }}" @checked(in_array($item['chunk_id'], $oldExcludes))> Exclude from generation</label>
            </article>
        @endforeach
        @if(empty($knowledgePreview['evidence']))<p>No eligible matching evidence. Add a reviewed scoped chunk or update the knowledge library before generation.</p>@endif
    </div>
</fieldset>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('[data-brand-preview]');
    if (!panel) return;
    const results = panel.querySelector('[data-knowledge-preview-results]');
    const exclusions = panel.querySelector('[data-exclusion-inputs]');
    const additions = panel.querySelector('[data-manual-additions]');
    function inputRow(parent, name, value) {
        const label = document.createElement('label'); label.className = 'block';
        label.append(name === 'knowledge_chunk_ids[]' ? 'Add chunk ID ' : 'Exclude chunk ID ');
        const input = document.createElement('input'); input.type = 'number'; input.min = '1'; input.required = true; input.name = name; input.value = value;
        const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Remove'; remove.addEventListener('click', () => label.remove());
        label.append(input, remove); parent.append(label);
    }
    panel.querySelector('[data-add-chunk]').addEventListener('click', () => inputRow(additions, 'knowledge_chunk_ids[]', ''));
    panel.addEventListener('change', event => {
        const id = event.target.dataset.excludeChunk;
        if (!id) return;
        exclusions.querySelectorAll('input').forEach(input => { if (input.value === id) input.parentElement.remove(); });
        if (event.target.checked) inputRow(exclusions, 'excluded_knowledge_chunk_ids[]', id);
    });
    panel.querySelector('[data-refresh-preview]').addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true;
        const error = panel.querySelector('[data-preview-error]'); error.textContent = '';
        try {
            const payload = {};
            ['knowledge_chunk_ids', 'excluded_knowledge_chunk_ids'].forEach(key => payload[key] = [...panel.querySelectorAll(`[name="${key}[]"]`)].filter(input => input.type !== 'checkbox' || input.checked).map(input => input.value).filter(Boolean));
            const response = await fetch(@json(route('admin.content-briefs.knowledge-preview', $brief)), {method:'POST', headers:{'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':panel.closest('form').querySelector('[name="_token"]').value}, body:JSON.stringify(payload)});
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Preview failed');
            results.replaceChildren();
            const text = (parent, value) => {const p = document.createElement('p'); p.textContent = value; parent.append(p);};
            data.warnings.forEach(warning => text(results, warning));
            data.evidence.forEach(item => {
                const article = document.createElement('article'); article.className = 'rounded border p-3 text-sm';
                text(article, `${item.chunk_title || item.knowledge_base_name} · #${item.chunk_id} · ${item.selection_method}`);
                text(article, item.content);
                text(article, [item.source_name, item.version_label, item.review_status, item.authority_level].filter(Boolean).join(' · '));
                if (/^https?:\/\//i.test(item.source_url || '')) {const a = document.createElement('a'); a.href = item.source_url; a.textContent = item.source_url; a.target = '_blank'; a.rel = 'noopener noreferrer'; article.append(a);}
                const label = document.createElement('label'); label.className = 'block'; const check = document.createElement('input'); check.type = 'checkbox'; check.dataset.excludeChunk = item.chunk_id; label.append(check, ' Exclude from generation'); article.append(label); results.append(article);
            });
            if (!data.evidence.length) text(results, 'No eligible matching evidence. Add a reviewed scoped chunk or update the knowledge library before generation.');
        } catch (exception) { error.textContent = exception.message; }
        finally { button.disabled = false; }
    });
});
</script>
@endpush
