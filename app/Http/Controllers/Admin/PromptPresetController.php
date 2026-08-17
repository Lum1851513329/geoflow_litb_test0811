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
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
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

        $query = PromptPreset::query()
            ->with(['currentVersion:id,prompt_preset_id,version,lifecycle_status'])
            ->orderBy('preset_key');
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
            'moduleVersions' => $this->moduleVersionChoices(),
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
            ->select(['id', 'prompt_preset_id', 'version', 'lifecycle_status', 'change_note', 'published_at', 'created_at'])
            ->orderByDesc('version')
            ->paginate(20, ['*'], 'versions_page')
            ->withQueryString();
        $selectedQuery = $preset->versions();
        $selected = $request->query->has('version_id')
            ? $selectedQuery->whereKey((int) $request->query('version_id'))->firstOrFail()
            : $selectedQuery->orderByDesc('version')->first();
        $selected?->load([
            'moduleVersions' => fn ($query) => $query->select([
                'id', 'prompt_preset_version_id', 'prompt_module_version_id', 'sort_order', 'is_required',
            ])->orderBy('sort_order'),
            'moduleVersions.promptModuleVersion' => fn ($query) => $query->select([
                'id', 'prompt_module_id', 'version', 'lifecycle_status',
            ]),
            'moduleVersions.promptModuleVersion.module' => fn ($query) => $query->select([
                'id', 'module_key', 'display_name', 'module_type',
            ]),
        ]);
        $moduleVersions = $this->moduleVersionChoices();
        if ($selected !== null) {
            $selectedChoices = $selected->moduleVersions
                ->pluck('promptModuleVersion')
                ->filter(fn (mixed $version): bool => $version instanceof PromptModuleVersion);
            $moduleVersions = $selectedChoices
                ->concat($moduleVersions)
                ->unique(fn (PromptModuleVersion $version): int => (int) $version->id)
                ->values();
        }
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
            'moduleVersions' => $moduleVersions,
        ]);
    }

    public function moduleVersionOptions(Request $request): JsonResponse
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', PromptPreset::class);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $search = trim((string) ($validated['q'] ?? ''));
        $query = $this->moduleVersionOptionQuery($search);
        $paginator = $query->paginate(25)->withQueryString();
        $paginator->through(fn (PromptModuleVersion $version): array => [
            'id' => (int) $version->id,
            'module_id' => (int) $version->prompt_module_id,
            'module_key' => (string) $version->module?->module_key,
            'display_name' => (string) $version->module?->display_name,
            'version' => (int) $version->version,
            'lifecycle_status' => (string) $version->lifecycle_status,
        ]);

        return response()->json($paginator);
    }

    public function storeVersion(StorePromptPresetRequest $request, PromptPreset $preset): RedirectResponse
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        try {
            $version = DB::transaction(function () use ($preset, $data, $actor): PromptPresetVersion {
                $lockedPreset = PromptPreset::query()->whereKey($preset->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $lockedPreset);
                $lockedPreset->update($this->mutableIdentityData($data, $actor));

                return $this->presets->createVersion(
                    $lockedPreset,
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
        return $this->mutateLifecycle(
            $request,
            $preset,
            $version,
            'publishVersion',
            'prompt_preset_version:published',
            __('admin.prompt_presets.messages.published'),
            fn (PromptPresetVersion $lockedVersion, Admin $actor, string $reason): PromptPresetVersion => $this->presets->publish($lockedVersion, $actor, $reason),
        );
    }

    public function deprecate(Request $request, PromptPreset $preset, PromptPresetVersion $version): RedirectResponse
    {
        return $this->mutateLifecycle(
            $request,
            $preset,
            $version,
            'deprecateVersion',
            'prompt_preset_version:deprecated',
            __('admin.prompt_presets.messages.deprecated'),
            fn (PromptPresetVersion $lockedVersion, Admin $actor, string $reason): PromptPresetVersion => $this->presets->deprecate($lockedVersion, $actor, $reason),
        );
    }

    public function archive(Request $request, PromptPreset $preset, PromptPresetVersion $version): RedirectResponse
    {
        return $this->mutateLifecycle(
            $request,
            $preset,
            $version,
            'archiveVersion',
            'prompt_preset_version:archived',
            __('admin.prompt_presets.messages.archived'),
            fn (PromptPresetVersion $lockedVersion, Admin $actor, string $reason): PromptPresetVersion => $this->presets->archive($lockedVersion, $actor, $reason),
        );
    }

    /** @param Closure(PromptPresetVersion, Admin, string): PromptPresetVersion $mutation */
    private function mutateLifecycle(
        Request $request,
        PromptPreset $preset,
        PromptPresetVersion $version,
        string $ability,
        string $action,
        string $message,
        Closure $mutation,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $reason = (string) $validated['reason'];
        try {
            [$lockedPreset, $changed] = DB::transaction(function () use ($preset, $version, $actor, $ability, $mutation, $reason): array {
                $lockedPreset = PromptPreset::query()->whereKey($preset->id)->lockForUpdate()->firstOrFail();
                $lockedVersion = PromptPresetVersion::query()
                    ->whereKey($version->id)
                    ->where('prompt_preset_id', $lockedPreset->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                Gate::forUser($actor)->authorize($ability, [$lockedPreset, $lockedVersion]);

                return [$lockedPreset, $mutation($lockedVersion, $actor, $reason)];
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
        $this->log($actor, $action, $lockedPreset, $changed, $reason);

        return redirect()->route('admin.prompt-presets.edit', $preset)
            ->with('message', $message);
    }

    /** @return Collection<int, PromptModuleVersion> */
    private function moduleVersionChoices(): Collection
    {
        return $this->moduleVersionOptionQuery('')->limit(50)->get();
    }

    /** @return Builder<PromptModuleVersion> */
    private function moduleVersionOptionQuery(string $search)
    {
        return PromptModuleVersion::query()
            ->select(['id', 'prompt_module_id', 'version', 'lifecycle_status'])
            ->where('lifecycle_status', '!=', PromptModuleVersion::STATUS_ARCHIVED)
            ->when($search !== '', fn ($query) => $query->whereHas('module', function ($moduleQuery) use ($search): void {
                $moduleQuery->where(function ($searchQuery) use ($search): void {
                    $searchQuery->where('module_key', 'like', '%'.$search.'%')
                        ->orWhere('display_name', 'like', '%'.$search.'%');
                });
            }))
            ->with('module:id,module_key,display_name,module_type')
            ->orderBy('prompt_module_id')
            ->orderByDesc('version');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function identityData(array $data): array
    {
        return [
            'preset_key' => $data['preset_key'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'is_system' => (bool) ($data['is_system'] ?? false),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function mutableIdentityData(array $data, Admin $actor): array
    {
        $identity = [
            'preset_key' => $data['preset_key'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
        ];
        if (array_key_exists('is_enabled', $data)) {
            $identity['is_enabled'] = (bool) $data['is_enabled'];
        }
        if (array_key_exists('is_system', $data) && $actor->isSuperAdmin()) {
            $identity['is_system'] = (bool) $data['is_system'];
        }

        return $identity;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function versionData(array $data): array
    {
        return [
            'matching_rules' => $data['matching_rules'],
            'generation_policy' => $data['generation_policy'],
            'conflict_policy' => $data['conflict_policy'] ?? null,
            'blueprint_template' => $data['blueprint_template'] ?? null,
            'default_brief_fields' => $data['default_brief_fields'] ?? null,
            'change_note' => $data['change_note'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private function bindings(array $data): array
    {
        return collect($data['module_versions'])
            ->values()
            ->map(fn (array $binding, int $index): array => [
                ...$binding,
                '_input_index' => $index,
                '_requested_order' => (int) ($binding['sort_order'] ?? $index + 1),
            ])
            ->sortBy(fn (array $binding): array => [$binding['_requested_order'], $binding['_input_index']])
            ->values()
            ->map(
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
