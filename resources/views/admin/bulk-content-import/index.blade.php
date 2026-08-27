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
@endsection
