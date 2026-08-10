<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PromptComposition\PromptModuleService;
use App\Exceptions\ImmutablePromptVersionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishPromptModuleVersionRequest;
use App\Http\Requests\Admin\StorePromptModuleRequest;
use App\Http\Requests\Admin\StorePromptModuleVersionRequest;
use App\Http\Requests\Admin\UpdatePromptModuleRequest;
use App\Models\Admin;
use App\Models\ContentGenerationRunModule;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PromptModuleController extends Controller
{
    public function __construct(private readonly PromptModuleService $modules) {}

    public function index(Request $request): View
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', PromptModule::class);

        $query = PromptModule::query()
            ->with(['currentVersion:id,prompt_module_id,version,lifecycle_status'])
            ->orderBy('module_type')
            ->orderBy('module_key');
        if (($search = trim((string) $request->query('q'))) !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('module_key', 'like', '%'.$search.'%')
                    ->orWhere('display_name', 'like', '%'.$search.'%');
            });
        }
        if (($type = trim((string) $request->query('type'))) !== '') {
            $query->where('module_type', $type);
        }

        return view('admin.prompt-modules.index', [
            'pageTitle' => __('admin.prompt_modules.title'),
            'activeMenu' => 'ai_config',
            'modules' => $query->paginate(20)->withQueryString(),
            'moduleTypes' => $this->moduleTypes(),
        ]);
    }

    public function store(StorePromptModuleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $module = $this->modules->createModule(
            $this->identityData($data),
            $this->versionData($data),
            $this->actor($request),
        );

        return redirect()->route('admin.prompt-modules.show', $module)
            ->with('message', __('admin.prompt_modules.messages.created'));
    }

    public function update(UpdatePromptModuleRequest $request, PromptModule $module): RedirectResponse
    {
        $actor = $this->actor($request);
        DB::transaction(function () use ($request, $module, $actor): void {
            $locked = PromptModule::query()->whereKey($module->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $data = $request->validated();
            $updates = [
                'display_name' => $data['display_name'],
                'module_type' => $data['module_type'],
                'description' => $data['description'] ?? null,
                'applicability' => $data['applicability'] ?? null,
                'default_priority' => (int) $data['default_priority'],
                'is_mandatory' => (bool) $data['is_mandatory'],
                'is_enabled' => (bool) $data['is_enabled'],
            ];
            if (array_key_exists('is_system', $data) && $actor->isSuperAdmin()) {
                $updates['is_system'] = (bool) $data['is_system'];
            }
            $locked->update($updates);
        });

        AdminActivityLogger::log($actor, 'prompt_module:updated', [
            'target_type' => 'prompt_module',
            'target_id' => $module->id,
            'details' => ['updated_fields' => array_keys($request->validated())],
        ]);

        return redirect()->route('admin.prompt-modules.show', $module)
            ->with('message', __('admin.prompt_modules.messages.updated'));
    }

    public function show(Request $request, PromptModule $module): View
    {
        Gate::forUser($this->actor($request))->authorize('view', $module);

        $versions = $module->versions()
            ->select(['id', 'prompt_module_id', 'version', 'content_sha256', 'lifecycle_status', 'change_note', 'published_at', 'created_at'])
            ->orderByDesc('version')
            ->paginate(20, ['*'], 'versions_page')
            ->withQueryString();
        $selectedQuery = $module->versions();
        $selected = $request->query->has('version_id')
            ? $selectedQuery->whereKey((int) $request->query('version_id'))->firstOrFail()
            : $selectedQuery->orderByDesc('version')->first();
        $compareTo = null;
        if ($selected !== null) {
            $compareTo = $request->query->has('compare_to')
                ? $module->versions()->whereKey((int) $request->query('compare_to'))->firstOrFail()
                : $module->versions()->where('version', '<', $selected->version)->orderByDesc('version')->first();
        }
        [$diffLines, $diffMetadata] = $selected !== null && $compareTo !== null
            ? $this->lineDiff((string) $compareTo->content, (string) $selected->content)
            : [[], ['truncated' => false, 'old_line_count' => 0, 'new_line_count' => 0, 'compared_line_limit' => 500]];

        $usages = ContentGenerationRunModule::query()
            ->when($selected !== null, fn ($query) => $query->where('prompt_module_version_id', $selected->id))
            ->when($selected === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->with(['run.article:id,title,slug'])
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'usage_page')
            ->withQueryString();

        return view('admin.prompt-modules.show', [
            'pageTitle' => $module->display_name,
            'activeMenu' => 'ai_config',
            'module' => $module,
            'moduleTypes' => $this->moduleTypes(),
            'versions' => $versions,
            'selectedVersion' => $selected,
            'compareVersion' => $compareTo,
            'diffLines' => $diffLines,
            'diffMetadata' => $diffMetadata,
            'usages' => $usages,
        ]);
    }

    public function storeVersion(StorePromptModuleVersionRequest $request, PromptModule $module): RedirectResponse
    {
        $version = $this->modules->createVersion($module, $this->versionData($request->validated()), $this->actor($request));

        return redirect()->route('admin.prompt-modules.show', [$module, 'version_id' => $version->id])
            ->with('message', __('admin.prompt_modules.messages.version_created'));
    }

    public function publish(
        PublishPromptModuleVersionRequest $request,
        PromptModule $module,
        PromptModuleVersion $version,
    ): RedirectResponse {
        try {
            $this->modules->publish($version, $this->actor($request), $request->string('reason')->toString());
        } catch (ImmutablePromptVersionException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return redirect()->route('admin.prompt-modules.show', $module)
            ->with('message', __('admin.prompt_modules.messages.published'));
    }

    public function archive(Request $request, PromptModule $module, PromptModuleVersion $version): RedirectResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('archiveVersion', [$module, $version]);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->modules->archive($version, $actor, (string) $validated['reason']);
        } catch (ImmutablePromptVersionException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return redirect()->route('admin.prompt-modules.show', $module)
            ->with('message', __('admin.prompt_modules.messages.archived'));
    }

    public function deprecate(Request $request, PromptModule $module, PromptModuleVersion $version): RedirectResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('deprecateVersion', [$module, $version]);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->modules->deprecate($version, $actor, (string) $validated['reason']);
        } catch (ImmutablePromptVersionException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return redirect()->route('admin.prompt-modules.show', $module)
            ->with('message', __('admin.prompt_modules.messages.deprecated'));
    }

    public function destroyVersion(Request $request, PromptModule $module, PromptModuleVersion $version): RedirectResponse
    {
        Gate::forUser($this->actor($request))->authorize('deleteVersion', [$module, $version]);

        $used = $version->presetBindings()->exists()
            || ContentGenerationRunModule::query()->where('prompt_module_version_id', $version->id)->exists();
        $message = $used
            ? __('admin.prompt_modules.validation.version_in_use')
            : __('admin.prompt_modules.validation.version_immutable');

        return redirect()->route('admin.prompt-modules.show', $module)
            ->withErrors(['version' => $message]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function identityData(array $data): array
    {
        return [
            'module_key' => $data['module_key'],
            'display_name' => $data['display_name'],
            'module_type' => $data['module_type'],
            'description' => $data['description'] ?? null,
            'is_system' => (bool) ($data['is_system'] ?? false),
            'applicability' => $data['applicability'] ?? null,
            'is_mandatory' => (bool) ($data['is_mandatory'] ?? false),
            'default_priority' => (int) $data['default_priority'],
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function versionData(array $data): array
    {
        return [
            'content' => $data['content'],
            'variable_contract' => $data['variable_contract'],
            'output_contract' => $data['output_contract'] ?? null,
            'parameter_defaults' => $data['parameter_defaults'] ?? null,
            'change_note' => $data['change_note'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function moduleTypes(): array
    {
        return collect([
            PromptModule::TYPE_FOUNDATION,
            PromptModule::TYPE_GEO,
            PromptModule::TYPE_SEO,
            PromptModule::TYPE_CONTENT_TYPE,
            PromptModule::TYPE_PLATFORM,
            PromptModule::TYPE_LANGUAGE_MARKET,
            PromptModule::TYPE_KNOWLEDGE_POLICY,
            PromptModule::TYPE_OUTPUT_FORMAT,
            PromptModule::TYPE_QUALITY_CHECK,
        ])->mapWithKeys(fn (string $type): array => [$type => __('admin.prompt_modules.types.'.$type)])->all();
    }

    /** @return array{0:list<array{kind:string,line:string}>,1:array{truncated:bool,old_line_count:int,new_line_count:int,compared_line_limit:int}} */
    private function lineDiff(string $old, string $new): array
    {
        $oldAllLines = preg_split('/\R/u', $old) ?: [];
        $newAllLines = preg_split('/\R/u', $new) ?: [];
        $oldLineCount = count($oldAllLines);
        $newLineCount = count($newAllLines);
        $oldLines = $this->boundedDiffLines($oldAllLines);
        $newLines = $this->boundedDiffLines($newAllLines);
        $rows = count($oldLines);
        $columns = count($newLines);
        $matrix = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($oldIndex = $rows - 1; $oldIndex >= 0; $oldIndex--) {
            for ($newIndex = $columns - 1; $newIndex >= 0; $newIndex--) {
                $matrix[$oldIndex][$newIndex] = $oldLines[$oldIndex] === $newLines[$newIndex]
                    ? $matrix[$oldIndex + 1][$newIndex + 1] + 1
                    : max($matrix[$oldIndex + 1][$newIndex], $matrix[$oldIndex][$newIndex + 1]);
            }
        }

        $diff = [];
        $oldIndex = 0;
        $newIndex = 0;
        while ($oldIndex < $rows || $newIndex < $columns) {
            if ($oldIndex < $rows && $newIndex < $columns && $oldLines[$oldIndex] === $newLines[$newIndex]) {
                $diff[] = ['kind' => 'same', 'line' => $oldLines[$oldIndex]];
                $oldIndex++;
                $newIndex++;
            } elseif ($newIndex < $columns && ($oldIndex >= $rows || $matrix[$oldIndex][$newIndex + 1] >= $matrix[$oldIndex + 1][$newIndex])) {
                $diff[] = ['kind' => 'added', 'line' => $newLines[$newIndex++]];
            } else {
                $diff[] = ['kind' => 'removed', 'line' => $oldLines[$oldIndex++]];
            }
        }

        return [$diff, [
            'truncated' => $oldLineCount > 500 || $newLineCount > 500,
            'old_line_count' => $oldLineCount,
            'new_line_count' => $newLineCount,
            'compared_line_limit' => 500,
        ]];
    }

    /** @param list<string> $lines @return list<string> */
    private function boundedDiffLines(array $lines): array
    {
        if (count($lines) <= 500) {
            return $lines;
        }

        return array_merge(
            array_slice($lines, 0, 250),
            ['[… bounded diff omits middle lines …]'],
            array_slice($lines, -250),
        );
    }

    private function actor(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
