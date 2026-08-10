<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PromptComposition\PromptPresetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromptPresetRequest;
use App\Models\Admin;
use App\Models\ContentGenerationRun;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Support\AdminActivityLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class PromptPresetController extends Controller
{
    public function __construct(private readonly PromptPresetService $presets) {}

    public function index(Request $request): View
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', PromptPreset::class);

        $query = PromptPreset::query()->with('currentVersion')->orderBy('preset_key');
        if (($search = trim((string) $request->query('q'))) !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('preset_key', 'like', '%'.$search.'%')
                    ->orWhere('display_name', 'like', '%'.$search.'%');
            });
        }

        return view('admin.prompt-presets.index', [
            'pageTitle' => __('admin.prompt_presets.title'),
            'activeMenu' => 'ai_config',
            'presets' => $query->paginate(20)->withQueryString(),
            'moduleVersions' => $this->moduleVersionOptions(),
        ]);
    }

    public function store(StorePromptPresetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        try {
            $preset = $this->presets->createPreset(
                $this->identityData($data),
                $this->versionData($data),
                $this->bindings($data),
                $actor,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['module_versions' => $exception->getMessage()]);
        }
        $version = $preset->versions()->sole();
        $this->log($actor, 'prompt_preset:created', $preset, $version);

        return redirect()->route('admin.prompt-presets.edit', $preset)
            ->with('message', __('admin.prompt_presets.messages.created'));
    }

    public function edit(Request $request, PromptPreset $preset): View
    {
        Gate::forUser($this->actor($request))->authorize('view', $preset);

        $versions = $preset->versions()
            ->with(['moduleVersions.promptModuleVersion.module'])
            ->orderByDesc('version')
            ->get();
        $selected = $versions->firstWhere('id', (int) $request->query('version_id')) ?? $versions->first();
        $usages = ContentGenerationRun::query()
            ->when($selected !== null, fn ($query) => $query->where('preset_version_id', $selected->id))
            ->when($selected === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->with('article:id,title,slug')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'usage_page')
            ->withQueryString();

        return view('admin.prompt-presets.edit', [
            'pageTitle' => $preset->display_name,
            'activeMenu' => 'ai_config',
            'preset' => $preset,
            'versions' => $versions,
            'selectedVersion' => $selected,
            'usages' => $usages,
            'moduleVersions' => $this->moduleVersionOptions(),
        ]);
    }

    public function storeVersion(StorePromptPresetRequest $request, PromptPreset $preset): RedirectResponse
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        try {
            $version = DB::transaction(function () use ($preset, $data, $actor): PromptPresetVersion {
                $preset->update($this->identityData($data, $preset));

                return $this->presets->createVersion(
                    $preset,
                    $this->versionData($data),
                    $this->bindings($data),
                    $actor,
                );
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['module_versions' => $exception->getMessage()]);
        }
        $this->log($actor, 'prompt_preset_version:created', $preset, $version);

        return redirect()->route('admin.prompt-presets.edit', [$preset, 'version_id' => $version->id])
            ->with('message', __('admin.prompt_presets.messages.version_created'));
    }

    public function publish(Request $request, PromptPreset $preset, PromptPresetVersion $version): RedirectResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('publishVersion', [$preset, $version]);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $published = $this->presets->publish($version, $actor, (string) $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
        $this->log($actor, 'prompt_preset_version:published', $preset, $published, (string) $validated['reason']);

        return redirect()->route('admin.prompt-presets.edit', $preset)
            ->with('message', __('admin.prompt_presets.messages.published'));
    }

    /** @return Collection<int, PromptModuleVersion> */
    private function moduleVersionOptions()
    {
        return PromptModuleVersion::query()
            ->where('lifecycle_status', '!=', PromptModuleVersion::STATUS_ARCHIVED)
            ->with('module:id,module_key,display_name,module_type')
            ->orderBy('prompt_module_id')
            ->orderByDesc('version')
            ->get();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function identityData(array $data, ?PromptPreset $preset = null): array
    {
        return [
            'preset_key' => $data['preset_key'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'is_system' => (bool) ($data['is_system'] ?? $preset?->is_system ?? false),
            'is_enabled' => (bool) ($data['is_enabled'] ?? $preset?->is_enabled ?? true),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function versionData(array $data): array
    {
        return [
            'matching_rules' => $data['matching_rules'],
            'generation_policy' => $data['generation_policy'],
            'conflict_policy' => $data['conflict_policy'] ?? null,
            'change_note' => $data['change_note'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private function bindings(array $data): array
    {
        return collect($data['module_versions'])->values()->map(
            fn (array $binding, int $index): array => [
                'prompt_module_version_id' => (int) $binding['prompt_module_version_id'],
                'sort_order' => $index + 1,
                'is_required' => (bool) ($binding['is_required'] ?? false),
            ],
        )->all();
    }

    private function log(
        Admin $actor,
        string $action,
        PromptPreset $preset,
        PromptPresetVersion $version,
        ?string $reason = null,
    ): void {
        AdminActivityLogger::log($actor, $action, [
            'target_type' => 'prompt_preset_version',
            'target_id' => $version->id,
            'details' => array_filter([
                'preset_key' => $preset->preset_key,
                'version' => $version->version,
                'lifecycle_status' => $version->lifecycle_status,
                'module_version_ids' => $version->moduleVersions()->pluck('prompt_module_version_id')->all(),
                'reason_digest' => $reason === null ? null : hash('sha256', $reason),
                'reason_length' => $reason === null ? null : mb_strlen($reason),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function actor(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
