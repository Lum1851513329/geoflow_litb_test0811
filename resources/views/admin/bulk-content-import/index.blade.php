@extends('admin.layouts.app')

@section('content')
<div class="mx-auto max-w-2xl rounded-md border border-gray-200 bg-white p-6">
    <h1 class="text-lg font-semibold text-gray-900">批量导入文章任务</h1>
    <p class="mt-1 text-sm text-gray-500">上传填好的模板表格，系统按发布渠道/内容模板/分类及知识范围分组，自动创建任务。任意行校验失败时整批不导入。</p>
    <p class="mt-2 text-sm text-gray-500">新模板必填 brand（知识品牌）；directory 可限定目录及子目录，重名时请用完整路径。knowledge_site 是知识来源站点，不是 site 发布渠道；品牌只有一个来源站点时可留空，否则必须指定。</p>
    <p class="mt-2 text-sm text-gray-500">支持旧版 knowledge_base 模板，但同一行不能混填品牌范围与知识库。primary_keyword 用于知识检索；reference_urls 会先保存，仅在本地证据不足且启用外部验证时抓取。此导入不导入商品图片或视频。</p>
    <p class="mt-2 text-sm">
        <a href="{{ route('admin.bulk-content-import.template') }}" class="font-medium text-blue-600 hover:text-blue-700">下载导入模板（.xlsx）</a>
    </p>

    @if ($errors->any())
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    @if (session('message'))
        <div class="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.bulk-content-import.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700">表格文件（.xlsx）</label>
            <input type="file" name="spreadsheet" accept=".xlsx" required class="mt-1 w-full">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">AI 模型</label>
            <select name="ai_model_id" required class="mt-1 w-full rounded-md border-gray-300">
                @foreach ($aiModels as $aiModel)
                    <option value="{{ $aiModel->id }}">{{ $aiModel->name }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="need_review" value="1" checked>
            生成后需人工审核
        </label>
        <div>
            <label class="block text-sm font-medium text-gray-700">外部证据策略</label>
            <select name="external_evidence_policy" class="mt-1 w-full rounded-md border-gray-300">
                <option value="off">关闭（仅使用本地知识）</option>
                <option value="on_insufficient">本地证据不足时外部验证</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">允许的通用来源域名（可选）</label>
            <input name="external_general_domains" value="{{ old('external_general_domains') }}" placeholder="example.org, who.int" class="mt-1 w-full rounded-md border-gray-300">
            <p class="mt-1 text-xs text-gray-500">品牌事实仍只能使用所选知识站点的官方域名。</p>
        </div>
        <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">开始导入</button>
    </form>
</div>

<div class="mx-auto mt-6 max-w-2xl rounded-md border border-gray-200 bg-white p-6">
    <h2 class="text-lg font-semibold text-gray-900">从 Semrush 导出转换导入</h2>
    <p class="mt-1 text-sm text-gray-500">直接上传任意品牌的 Semrush 原始 CSV（Prompt Gap / AI 可见度报告，或经典 Topic Gap 关键词报告均可，无需手动清洗）。系统会按选定品牌的目录与内容模板映射主题，并按机会分排序；泛科普、购物建议和行业话题默认在本地证据不足时启用外部证据。先预览，确认无误后再导入生成任务。</p>
    <p class="mt-2 text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded p-2">品牌型主题使用所选品牌的官方知识范围；通用主题不会强制检索品牌知识库。外部证据策略会随每一行主题类型保存，可在确认导入时统一调整。</p>

    <form method="POST" action="{{ route('admin.bulk-content-import.semrush.preview') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700">Semrush 导出原始 CSV</label>
            <input type="file" name="semrush_csv" accept=".csv,.txt" required class="mt-1 w-full">
            <p class="mt-1 text-xs text-gray-500">支持 Prompt Gap 导出（含 topic_name/topic_volume/topic_difficulty/topic_intents 列）或经典 Topic Gap 导出（name/volume/difficulty/intents 列），直接上传即可，不用改列名。</p>
            @error('semrush_csv') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">发布渠道</label>
            <select name="distribution_channel_id" required class="mt-1 w-full rounded-md border-gray-300">
                @foreach ($distributionChannels as $channel)
                    <option value="{{ $channel->id }}">{{ $channel->name }} ({{ $channel->domain }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">内容模板 Preset（选填）</label>
            <select name="prompt_preset_id" class="mt-1 w-full rounded-md border-gray-300">
                <option value="">（自动按每行分类选择合适模板）</option>
                @foreach ($eligiblePresets as $preset)
                    <option value="{{ $preset->id }}">{{ $preset->display_name }} ({{ $preset->preset_key }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">留空时，每行主题会按其自动映射的分类各自选用最匹配的模板；如需整批统一使用同一模板，可在此手动指定。</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">知识品牌</label>
            <select name="knowledge_brand_id" required class="mt-1 w-full rounded-md border-gray-300">
                @foreach ($knowledgeBrands as $brand)
                    <option value="{{ $brand->id }}">{{ $brand->name }} ({{ $brand->key }})</option>
                @endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">知识来源站点（选填）</label>
                <select name="knowledge_site_id" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="">（品牌只有一个来源站点时留空）</option>
                    @foreach ($knowledgeSites as $site)
                        <option value="{{ $site->id }}">{{ $site->key }} ({{ $site->canonical_host }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">知识目录（选填）</label>
                <select name="knowledge_directory_id" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="">（不限定目录）</option>
                    @foreach ($knowledgeDirectories as $directory)
                        <option value="{{ $directory->id }}">{{ $directory->path }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">最多取前 N 个主题（按机会分排序）</label>
            <input type="number" name="limit" value="50" min="1" max="500" class="mt-1 w-full rounded-md border-gray-300">
        </div>
        <button class="rounded-md bg-gray-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-gray-800">转换并预览</button>
    </form>
</div>

@if (isset($semrushPreview))
<div class="mx-auto mt-6 max-w-2xl rounded-md border border-gray-200 bg-white p-6">
    <h2 class="text-lg font-semibold text-gray-900">预览结果</h2>

    <div class="mt-3 grid grid-cols-3 gap-3 text-sm">
        <div class="rounded-md bg-gray-50 p-3">
            <div class="text-xs text-gray-500">可用主题</div>
            <div class="text-lg font-semibold text-gray-900">{{ $semrushPreview['usable_count'] }}</div>
        </div>
        <div class="rounded-md bg-gray-50 p-3">
            <div class="text-xs text-gray-500">本次选取</div>
            <div class="text-lg font-semibold text-gray-900">{{ count($semrushPreview['accepted']) }} / {{ $semrushLimit }}</div>
        </div>
        <div class="rounded-md bg-gray-50 p-3">
            <div class="text-xs text-gray-500">已剔除</div>
            <div class="text-lg font-semibold text-gray-900">{{ array_sum($semrushPreview['rejected_counts']) }}</div>
        </div>
    </div>

    @if (!empty($semrushPreview['quarantined']))
        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
            <div class="font-semibold">已隔离 {{ count($semrushPreview['quarantined']) }} 个主题</div>
            <div class="mt-1">这些主题缺少可确认的目录、模板或品牌范围，不会进入导入列表；请补充配置后重新预览。</div>
            <ul class="mt-2 list-disc space-y-0.5 pl-5">
                @foreach (array_slice($semrushPreview['quarantined'], 0, 8) as $item)
                    <li>{{ $item['topic'] ?? '—' }}：{{ $item['reason'] ?? '无法解析' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (!empty($semrushPreview['rejected_counts']))
        <div class="mt-3 text-xs text-gray-500">
            剔除原因：
            @foreach ($semrushPreview['rejected_counts'] as $reason => $count)
                <span class="mr-2">{{ $reason }} × {{ $count }}（如「{{ $semrushPreview['rejected_examples'][$reason] }}」）</span>
            @endforeach
        </div>
    @endif

    @php $semrushDuplicateCount = collect($semrushPreview['accepted'])->where('possible_duplicate', true)->count(); @endphp
    @if ($semrushDuplicateCount > 0)
        <div class="mt-4 flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <span>发现 <b>{{ $semrushDuplicateCount }}</b> 个主题疑似已经写过文章（标题不同但内容重叠，可能是上次导出留下的），已在下方标黄，请核对后决定是否剔除。</span>
            <button type="button" onclick="document.querySelectorAll('.semrush-keep[data-duplicate=&quot;1&quot;]').forEach(el => el.checked = false); updateSemrushKeepCount();" class="ml-3 shrink-0 rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100">取消勾选这些疑似重复项</button>
        </div>
    @endif

    @error('keep')
        <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('admin.bulk-content-import.semrush.confirm') }}" class="mt-4 space-y-4" id="semrush-confirm-form">
        @csrf
        <input type="hidden" name="preview_token" value="{{ $semrushPreviewToken }}">

        <div class="flex items-center justify-between text-xs text-gray-500">
            <span>不合适的主题可取消勾选，剔除后再导入。</span>
            <span class="space-x-2">
                <button type="button" onclick="document.querySelectorAll('.semrush-keep').forEach(el => el.checked = true); updateSemrushKeepCount();" class="text-blue-600 hover:underline">全选</button>
                <button type="button" onclick="document.querySelectorAll('.semrush-keep').forEach(el => el.checked = false); updateSemrushKeepCount();" class="text-blue-600 hover:underline">全不选</button>
            </span>
        </div>

        <div class="max-h-96 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-white">
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                        <th class="py-1 pr-2"></th>
                        <th class="py-1 pr-2">#</th>
                        <th class="py-1 pr-2">主题</th>
                        <th class="py-1 pr-2">分类</th>
                        <th class="py-1 pr-2">知识模式</th>
                        <th class="py-1 pr-2 text-right">成交量</th>
                        <th class="py-1 pr-2 text-right">难度</th>
                        <th class="py-1 pr-2 text-right">机会分</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($semrushPreview['accepted'] as $i => $item)
                        <tr class="border-b border-gray-100 {{ $item['possible_duplicate'] ? 'bg-amber-50' : '' }}">
                            <td class="py-1 pr-2">
                                <input type="checkbox" name="keep[]" value="{{ $i }}" class="semrush-keep" data-duplicate="{{ $item['possible_duplicate'] ? '1' : '0' }}" onchange="updateSemrushKeepCount()" checked>
                            </td>
                            <td class="py-1 pr-2 text-gray-400">{{ $i + 1 }}</td>
                            <td class="py-1 pr-2">
                                {{ $item['topic'] }}
                                @if ($item['possible_duplicate'])
                                    <div class="mt-0.5 text-xs text-amber-700">
                                        ⚠ 疑似已写过，最相似：
                                        @foreach ($item['duplicate_matches'] as $match)
                                            <span class="mr-1">「{{ $match['title'] }}」({{ number_format($match['score'] * 100, 0) }}%)</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="py-1 pr-2 text-gray-600">{{ $item['category'] }}</td>
                            <td class="py-1 pr-2 text-gray-600">{{ $item['knowledge_mode'] ?? 'general' }}</td>
                            <td class="py-1 pr-2 text-right">{{ number_format($item['volume']) }}</td>
                            <td class="py-1 pr-2 text-right">{{ $item['difficulty'] }}</td>
                            <td class="py-1 pr-2 text-right">{{ number_format($item['score'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-4 border-t border-gray-200 pt-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">生成方式</label>
                <select name="generation_engine" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="quality_gated">内部 AI 生成（质量门禁，消耗 API 额度）</option>
                    <option value="workbuddy">WorkBuddy 外部生成（不消耗内部 API 额度）</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">选 WorkBuddy 时：本站只编好 Prompt + 检索好知识库片段就停下，不会调用付费模型；写作交给 WorkBuddy，完成后在「WorkBuddy 任务」页面提交内容即可发布。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">WorkBuddy 写作模板<span class="font-normal text-gray-400">（仅 WorkBuddy 方式用得上；不选就用系统默认的兜底说明，建议自己写一份）</span></label>
                <select name="prompt_id" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="">（不选，用默认兜底说明）</option>
                    @foreach ($workBuddyPrompts as $prompt)
                        <option value="{{ $prompt->id }}">{{ $prompt->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">这个模板和"批量导入"页面既有的内容 Prompt 是同一套东西（在「Prompt 管理」里新建/编辑），支持 <code class="rounded bg-gray-100 px-1">{{'{{title}}'}}</code> / <code class="rounded bg-gray-100 px-1">{{'{{keyword}}'}}</code> / <code class="rounded bg-gray-100 px-1">{{'{{Knowledge}}'}}</code> 变量和 <code class="rounded bg-gray-100 px-1">{{'{{#if Knowledge}}...{{/if}}'}}</code> 条件块——写一份像"给人的写作任务书"那样的正常 Prompt 就行，系统会把蓝图结构和检索到的证据都塞进 {{'{{Knowledge}}'}} 里。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">AI 模型<span class="font-normal text-gray-400">（WorkBuddy 方式下仅用于计算 Prompt 长度预算，不会被调用）</span></label>
                <select name="ai_model_id" required class="mt-1 w-full rounded-md border-gray-300">
                    @foreach ($aiModels as $aiModel)
                        <option value="{{ $aiModel->id }}">{{ $aiModel->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="need_review" value="1" checked>
                生成后需人工审核
            </label>
            <div>
                <label class="block text-sm font-medium text-gray-700">外部证据策略</label>
                <select name="external_evidence_policy" class="mt-1 w-full rounded-md border-gray-300">
                    <option value="on_insufficient" selected>本地证据不足时外部验证（推荐）</option>
                    <option value="off">关闭（仅使用本地知识）</option>
                </select>
            </div>
            <button type="submit" class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">确认导入（生成 <span id="semrush-keep-count">{{ count($semrushPreview['accepted']) }}</span> 篇任务）</button>
        </div>
    </form>

    <script>
        function updateSemrushKeepCount() {
            const count = document.querySelectorAll('.semrush-keep:checked').length;
            document.getElementById('semrush-keep-count').textContent = count;
        }
    </script>
</div>
@endif
@endsection
