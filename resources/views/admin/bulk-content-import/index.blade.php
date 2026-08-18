@extends('admin.layouts.app')

@section('content')
<div class="mx-auto max-w-2xl rounded-md border border-gray-200 bg-white p-6">
    <h1 class="text-lg font-semibold text-gray-900">批量导入文章任务</h1>
    <p class="mt-1 text-sm text-gray-500">上传填好的模板表格，系统会按站点/内容模板/分类分组，自动创建对应的任务。</p>
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
        <button class="rounded-md bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">开始导入</button>
    </form>
</div>
@endsection
