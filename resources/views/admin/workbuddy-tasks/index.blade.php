@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">WorkBuddy 任务</h1>
        <p class="mt-1 text-sm text-gray-600">Prompt + 知识库检索已经编好、等 WorkBuddy 写正文的任务。点进详情页能看到完整 Prompt，写完把正文粘贴回去就能提交发布——全程不调用内部付费模型。</p>
    </div>

    @if (session('message'))
        <div class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('message') }}</div>
    @endif

    <div class="overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">主题</th>
                    <th class="px-4 py-3">关键词</th>
                    <th class="px-4 py-3">分类</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($runs as $run)
                    @php
                        $statusLabel = match ($run->status) {
                            \App\Models\ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT => ['等待 WorkBuddy 写作', 'bg-purple-100 text-purple-800'],
                            \App\Models\ContentGenerationRun::STATUS_READY_FOR_REVIEW => ['已提交，待核对发布', 'bg-amber-100 text-amber-800'],
                            \App\Models\ContentGenerationRun::STATUS_ADOPTED => ['已发布', 'bg-green-100 text-green-800'],
                            default => [$run->status, 'bg-gray-100 text-gray-700'],
                        };
                    @endphp
                    <tr>
                        <td class="px-4 py-3 text-gray-400">{{ $run->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $run->contentBrief?->topic }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $run->contentBrief?->retrieval_keyword }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $run->contentBrief?->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusLabel[1] }}">{{ $statusLabel[0] }}</span></td>
                        <td class="px-4 py-3">
                            @if ($run->status === \App\Models\ContentGenerationRun::STATUS_ADOPTED && $run->article)
                                <a href="{{ route('admin.articles.edit', $run->article->id) }}" class="font-semibold text-blue-600 hover:text-blue-700">查看文章 →</a>
                            @else
                                <a href="{{ route('admin.content-generations.show', $run) }}" class="font-semibold text-blue-600 hover:text-blue-700">打开详情 →</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无 WorkBuddy 任务——先在「批量导入 → Semrush 导入」里把生成方式选成 WorkBuddy 外部生成。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $runs->links() }}</div>
</div>
@endsection
