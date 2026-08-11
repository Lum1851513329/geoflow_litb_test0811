@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.content_strategy.index.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.content_strategy.index.subtitle') }}</p>
        </div>
        <a href="{{ route('admin.content-briefs.create') }}" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.content_strategy.actions.new_brief') }}</a>
    </div>

    <form method="GET" class="flex gap-3 rounded-lg bg-white p-4 shadow">
        <input class="min-w-0 flex-1 rounded-md border-gray-300" name="q" value="{{ request('q') }}" placeholder="{{ __('admin.content_strategy.fields.search') }}">
        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">{{ __('admin.content_strategy.actions.filter') }}</button>
    </form>

    <section class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('admin.content_strategy.fields.topic') }}</th>
                        <th class="px-5 py-3">{{ __('admin.content_strategy.fields.locale') }}</th>
                        <th class="px-5 py-3">{{ __('admin.content_strategy.fields.status') }}</th>
                        <th class="px-5 py-3">{{ __('admin.content_strategy.fields.versions') }}</th>
                        <th class="px-5 py-3">{{ __('admin.content_strategy.fields.created_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($briefs as $brief)
                        <tr>
                            <td class="px-5 py-4">
                                <a class="font-semibold text-blue-600 hover:text-blue-700" href="{{ $brief->blueprint_versions_count > 0 ? route('admin.content-briefs.show', $brief) : route('admin.content-briefs.strategy', $brief) }}">{{ $brief->topic }}</a>
                                <div class="mt-1 max-w-xl truncate text-xs text-gray-500">{{ $brief->core_question }}</div>
                            </td>
                            <td class="px-5 py-4 text-gray-700">{{ $brief->language_code }} · {{ $brief->market_code }}</td>
                            <td class="px-5 py-4"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ $brief->status }}</span></td>
                            <td class="px-5 py-4 text-gray-600">{{ __('admin.content_strategy.fields.strategy_blueprint_versions', ['strategy' => $brief->strategy_versions_count, 'blueprint' => $brief->blueprint_versions_count]) }}</td>
                            <td class="px-5 py-4 text-gray-500">{{ $brief->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">{{ __('admin.content_strategy.index.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-5 py-4">{{ $briefs->links() }}</div>
    </section>
</div>
@endsection
