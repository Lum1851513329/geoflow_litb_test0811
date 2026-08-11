@extends('admin.layouts.app')

@section('content')
<div class="space-y-6 px-4 sm:px-0">
    @include('admin.partials.content-center-nav', ['activeSection' => 'modules'])

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.prompt_modules.title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.prompt_modules.subtitle') }}</p>
        </div>
        <a href="{{ route('admin.prompt-presets.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.prompt_modules.actions.manage_presets') }}</a>
    </div>

    <form method="GET" class="grid gap-3 rounded-lg bg-white p-4 shadow sm:grid-cols-[minmax(0,1fr)_220px_auto]">
        <input class="rounded-md border-gray-300" name="q" value="{{ request('q') }}" placeholder="{{ __('admin.prompt_modules.filters.search') }}">
        <select class="rounded-md border-gray-300" name="type">
            <option value="">{{ __('admin.prompt_modules.filters.all_types') }}</option>
            @foreach($moduleTypes as $type => $label)
                <option value="{{ $type }}" @selected(request('type') === $type)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white">{{ __('admin.prompt_modules.actions.filter') }}</button>
    </form>

    <section class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-5 py-3">{{ __('admin.prompt_modules.fields.module') }}</th><th class="px-5 py-3">{{ __('admin.prompt_modules.fields.type') }}</th><th class="px-5 py-3">{{ __('admin.prompt_modules.fields.current_version') }}</th><th class="px-5 py-3">{{ __('admin.prompt_modules.fields.status') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($modules as $module)
                        <tr>
                            <td class="px-5 py-4"><a class="font-semibold text-blue-600 hover:text-blue-700" href="{{ route('admin.prompt-modules.show', $module) }}">{{ $module->display_name }}</a><div class="font-mono text-xs text-gray-500">{{ $module->module_key }}</div></td>
                            <td class="px-5 py-4 text-gray-700">{{ $moduleTypes[$module->module_type] ?? $module->module_type }}</td>
                            <td class="px-5 py-4">{{ $module->currentVersion ? 'v'.$module->currentVersion->version : '—' }}</td>
                            <td class="px-5 py-4"><span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ $module->currentVersion?->lifecycle_status ?? __('admin.prompt_modules.status.none') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">{{ __('admin.prompt_modules.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-5 py-4">{{ $modules->links() }}</div>
    </section>

    <section class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.prompt_modules.create_title') }}</h2>
        <form method="POST" action="{{ route('admin.prompt-modules.store') }}" class="mt-5 grid gap-4 lg:grid-cols-2">
            @csrf
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.module_key') }}<input name="module_key" value="{{ old('module_key') }}" required class="mt-1 w-full rounded-md border-gray-300"></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.display_name') }}<input name="display_name" value="{{ old('display_name') }}" required class="mt-1 w-full rounded-md border-gray-300"></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.type') }}<select name="module_type" required class="mt-1 w-full rounded-md border-gray-300">@foreach($moduleTypes as $type => $label)<option value="{{ $type }}" @selected(old('module_type') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.priority') }}<input type="number" name="default_priority" value="{{ old('default_priority', 0) }}" required class="mt-1 w-full rounded-md border-gray-300"></label>
            <label class="lg:col-span-2 text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.description') }}<textarea name="description" rows="2" class="mt-1 w-full rounded-md border-gray-300">{{ old('description') }}</textarea></label>
            <label class="lg:col-span-2 text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.content') }}<textarea name="content" rows="10" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('content') }}</textarea></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.variable_contract') }}<textarea name="variable_contract" rows="6" required class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('variable_contract', "{\n  \"variables\": {}\n}") }}</textarea></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.output_contract') }}<textarea name="output_contract" rows="6" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('output_contract', '{}') }}</textarea></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.parameter_defaults') }}<textarea name="parameter_defaults" rows="4" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('parameter_defaults', '{}') }}</textarea></label>
            <label class="text-sm font-medium text-gray-700">{{ __('admin.prompt_modules.fields.applicability') }}<textarea name="applicability" rows="4" class="mt-1 w-full rounded-md border-gray-300 font-mono text-xs">{{ old('applicability', '{}') }}</textarea></label>
            <div class="flex flex-wrap items-center gap-5 lg:col-span-2">
                <input type="hidden" name="is_enabled" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', '1'))>{{ __('admin.prompt_modules.fields.enabled') }}</label>
                <input type="hidden" name="is_mandatory" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_mandatory" value="1" @checked(old('is_mandatory'))>{{ __('admin.prompt_modules.fields.mandatory') }}</label>
                @if(auth('admin')->user()?->isSuperAdmin())
                    <input type="hidden" name="is_system" value="0"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="is_system" value="1" @checked(old('is_system'))>{{ __('admin.prompt_modules.fields.system') }}</label>
                @endif
            </div>
            <div class="lg:col-span-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">{{ __('admin.prompt_modules.actions.create') }}</button></div>
        </form>
    </section>
</div>
@endsection
