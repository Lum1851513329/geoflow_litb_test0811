@php
    $contentCenterItems = [
        'briefs' => ['route' => 'admin.content-briefs.index', 'label' => __('admin.content_center.briefs')],
        'modules' => ['route' => 'admin.prompt-modules.index', 'label' => __('admin.content_center.prompt_modules')],
        'presets' => ['route' => 'admin.prompt-presets.index', 'label' => __('admin.content_center.prompt_presets')],
    ];
@endphp

<nav aria-label="{{ __('admin.content_center.title') }}" class="flex flex-wrap gap-2">
    @foreach($contentCenterItems as $key => $item)
        <a href="{{ route($item['route']) }}"
           @class([
               'rounded-md px-3 py-2 text-sm font-medium transition-colors',
               'bg-blue-600 text-white' => $activeSection === $key,
               'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50' => $activeSection !== $key,
           ])>
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
