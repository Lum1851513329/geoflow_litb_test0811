@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.title-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.title_ai_generate.page_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.title_ai_generate.page_subtitle', ['name' => $library->name]) }}</p>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.title_ai_generate.section.config') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.title_ai_generate.section.config_desc') }}</p>
            </div>
            <form method="POST" action="{{ route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $library->id]) }}" class="p-6 space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.keyword_library') }}</label>
                        <select name="keyword_library_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 text-sm" required>
                            <option value="">{{ __('admin.title_ai_generate.option.select_keyword_library') }}</option>
                            @foreach ($keywordLibraries as $keywordLibrary)
                                <option value="{{ (int) $keywordLibrary->id }}" @selected((int) old('keyword_library_id') === (int) $keywordLibrary->id)>
                                    {{ $keywordLibrary->name }} ({{ (int) ($keywordLibrary->keyword_count ?? 0) }} {{ __('admin.title_ai_generate.option.keyword_count_suffix') }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.ai_model') }}</label>
                        <select name="ai_model_id" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 text-sm" required>
                            <option value="">{{ __('admin.title_ai_generate.option.select_ai_model') }}</option>
                            @foreach ($aiModels as $aiModel)
                                <option value="{{ (int) $aiModel->id }}" @selected((int) old('ai_model_id') === (int) $aiModel->id)>
                                    {{ $aiModel->name }} ({{ $aiModel->model_id }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">关键词分类（包含子分类）</label>
                        <input type="hidden" name="taxonomy_node_id" id="title-taxonomy-node" value="{{ old('taxonomy_node_id') }}">
                        <div id="title-taxonomy-levels" class="grid gap-2"><p class="text-sm text-gray-500">请先选择关键词库</p></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Topic Cluster（可选）</label>
                        <select name="keyword_cluster_id" id="title-keyword-cluster" class="block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="">使用所选分类中的全部关键词</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">标题输出语言</label>
                        <select name="output_language" class="block w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                            @foreach (['en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'pt' => 'Português'] as $code => $label)
                                <option value="{{ $code }}" @selected(old('output_language', 'en') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.count') }}</label>
                        <input type="number" name="title_count" value="{{ old('title_count', 10) }}" min="1" max="50" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.style') }}</label>
                        <select name="title_style" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 text-sm" required>
                            @foreach (['professional', 'attractive', 'seo', 'creative', 'question'] as $style)
                                <option value="{{ $style }}" @selected(old('title_style', 'professional') === $style)>
                                    {{ __('admin.title_ai_generate.style.'.$style) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.title_ai_generate.field.custom_prompt') }}</label>
                    <textarea name="custom_prompt" rows="4" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-purple-500 focus:border-purple-500 text-sm" placeholder="{{ __('admin.title_ai_generate.placeholder.custom_prompt') }}">{{ old('custom_prompt') }}</textarea>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center px-5 py-2.5 border border-transparent rounded-md text-sm font-medium text-white bg-purple-600 hover:bg-purple-700">
                        <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.title_ai_generate.button.sync') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 bg-purple-50 border border-purple-200 rounded-lg p-5">
            <h3 class="text-sm font-semibold text-purple-900">{{ __('admin.title_ai_generate.section.instructions') }}</h3>
            <ul class="mt-3 space-y-1 text-sm text-purple-800">
                <li>{{ __('admin.title_ai_generate.instructions.keyword_library') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.ai_model') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.count') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.style') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.custom_prompt') }}</li>
                <li>{{ __('admin.title_ai_generate.instructions.saved_titles') }}</li>
            </ul>
        </div>
    </div>
    <script>
        (() => {
            const library = document.querySelector('[name="keyword_library_id"]');
            const nodeInput = document.getElementById('title-taxonomy-node');
            const levels = document.getElementById('title-taxonomy-levels');
            const cluster = document.getElementById('title-keyword-cluster');
            const endpoint = @json(route('admin.title-libraries.ai-generate.scopes', ['libraryId' => (int) $library->id]));
            const loadLevel = async (parentId = '', level = 0) => {
                [...levels.querySelectorAll('select')].slice(level).forEach(element => element.remove());
                cluster.innerHTML = '<option value="">使用所选分类中的全部关键词</option>';
                nodeInput.value = parentId;
                if (!library.value) {
                    levels.innerHTML = '<p class="text-sm text-gray-500">请先选择关键词库</p>';
                    return;
                }
                const params = new URLSearchParams({keyword_library_id: library.value});
                if (parentId) params.set('parent_id', parentId);
                if (parentId) params.set('selected_node_id', parentId);
                const response = await fetch(`${endpoint}?${params.toString()}`, {headers: {'Accept': 'application/json'}});
                const data = await response.json();
                if (data.nodes.length) {
                    levels.querySelector('p')?.remove();
                    const select = document.createElement('select');
                    select.className = 'block w-full border-gray-300 rounded-md shadow-sm text-sm';
                    select.innerHTML = '<option value="">请选择分类层级</option>' + data.nodes.map(item => `<option value="${item.id}">${item.name}</option>`).join('');
                    select.addEventListener('change', () => loadLevel(select.value, level + 1));
                    levels.appendChild(select);
                }
                data.clusters.forEach(item => cluster.add(new Option(`${item.name} · ${item.language_code}/${item.market_scope} · ${item.keywords_count} keywords`, item.id)));
            };
            library.addEventListener('change', () => loadLevel());
            if (library.value) loadLevel();
        })();
    </script>
@endsection
