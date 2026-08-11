<?php

namespace App\Http\Requests\Admin;

use App\Domain\PromptComposition\PromptModuleResolver;
use App\Domain\PromptComposition\PromptPresetSelector;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Throwable;

final class RequestContentGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $brief = $this->route('brief');

        return $admin instanceof Admin
            && $brief instanceof ContentBrief
            && Gate::forUser($admin)->allows('update', $brief);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'strategy_version_id' => ['required', 'integer', 'exists:content_strategy_versions,id'],
            'blueprint_version_id' => ['required', 'integer', 'exists:content_blueprint_versions,id'],
            'preset_version_id' => ['required', 'integer', 'exists:prompt_preset_versions,id'],
            'ai_model_id' => ['required', 'integer', 'exists:ai_models,id'],
            'idempotency_key' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $idempotencyKey = trim((string) $this->input('idempotency_key', ''));
            if ($idempotencyKey !== ''
                && ContentGenerationRun::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return;
            }
            $brief = $this->route('brief');
            if ($brief instanceof ContentBrief) {
                $strategy = ContentStrategyVersion::query()
                    ->whereKey($this->integer('strategy_version_id'))
                    ->where('content_brief_id', $brief->id)
                    ->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED)
                    ->first();
                if ($strategy === null) {
                    $validator->errors()->add('strategy_version_id', __('admin.content_strategy.validation.strategy_required'));
                }
                $blueprint = ContentBlueprintVersion::query()
                    ->whereKey($this->integer('blueprint_version_id'))
                    ->where('content_brief_id', $brief->id)
                    ->where('content_strategy_version_id', $this->integer('strategy_version_id'))
                    ->where('lifecycle_status', ContentBlueprintVersion::STATUS_CONFIRMED)
                    ->first();
                if ($blueprint === null) {
                    $validator->errors()->add('blueprint_version_id', __('admin.content_strategy.validation.blueprint_required'));
                }
            }
            $preset = $brief instanceof ContentBrief
                ? app(PromptPresetSelector::class)->eligibleVersion($brief, $this->integer('preset_version_id'))
                : null;
            if ($preset === null) {
                $validator->errors()->add('preset_version_id', __('admin.content_strategy.validation.preset_unavailable'));
            } else {
                if ($brief instanceof ContentBrief) {
                    try {
                        app(PromptModuleResolver::class)->resolve(
                            $preset,
                            app(PromptPresetSelector::class)->context($brief),
                        );
                    } catch (Throwable $exception) {
                        report($exception);
                        $validator->errors()->add('preset_version_id', __('admin.content_strategy.validation.modules_unavailable'));
                    }
                }
            }
            $model = AiModel::query()->find($this->integer('ai_model_id'));
            if ($model === null || (string) $model->status !== 'active' || (string) $model->model_type === 'embedding') {
                $validator->errors()->add('ai_model_id', __('admin.content_strategy.validation.model_unavailable'));
            }
        });
    }
}
