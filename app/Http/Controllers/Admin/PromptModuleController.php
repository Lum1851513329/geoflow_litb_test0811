<?php

namespace App\Http\Controllers\Admin;

use App\Domain\PromptComposition\PromptModuleService;
use App\Exceptions\ImmutablePromptVersionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishPromptModuleVersionRequest;
use App\Http\Requests\Admin\StorePromptModuleRequest;
use App\Http\Requests\Admin\StorePromptModuleVersionRequest;
use App\Models\Admin;
use App\Models\ContentGenerationRunModule;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PromptModuleController extends Controller
{
    public function __construct(private readonly PromptModuleService $modules) {}

    public function index(Request $request): View
    {
        Gate::forUser($this->actor($request))->authorize('viewAny', PromptModule::class);

        $query = PromptModule::query()->with('currentVersion')->orderBy('module_type')->orderBy('module_key');
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

    public function show(Request $request, PromptModule $module): View
    {
        Gate::forUser($this->actor($request))->authorize('view', $module);

        $allVersions = $module->versions()->orderByDesc('version')->get();
        $selected = $allVersions->firstWhere('id', (int) $request->query('version_id')) ?? $allVersions->first();
        $compareTo = null;
        if ($selected !== null) {
            $requestedCompareId = (int) $request->query('compare_to');
            $compareTo = $requestedCompareId > 0
                ? $allVersions->firstWhere('id', $requestedCompareId)
                : $allVersions->first(fn (PromptModuleVersion $item): bool => $item->version < $selected->version);
        }

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
            'versions' => $allVersions,
            'selectedVersion' => $selected,
            'compareVersion' => $compareTo,
            'diffLines' => $selected !== null && $compareTo !== null
                ? $this->lineDiff((string) $compareTo->content, (string) $selected->content)
                : [],
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

    /** @return list<array{kind:string,line:string}> */
    private function lineDiff(string $old, string $new): array
    {
        $oldLines = array_slice(preg_split('/\R/u', $old) ?: [], 0, 500);
        $newLines = array_slice(preg_split('/\R/u', $new) ?: [], 0, 500);
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

        return $diff;
    }

    private function actor(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
