<?php

namespace App\Http\Requests\Admin;

use App\Domain\PromptComposition\PromptModuleResolver;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class RequestContentGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin
            && $this->route('brief') instanceof ContentBrief;
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
            $preset = PromptPresetVersion::query()->with('preset')->find($this->integer('preset_version_id'));
            if ($preset === null
                || ! $preset->preset?->is_enabled
                || in_array($preset->lifecycle_status, [PromptPresetVersion::STATUS_DRAFT, PromptPresetVersion::STATUS_ARCHIVED], true)) {
                $validator->errors()->add('preset_version_id', __('admin.content_strategy.validation.preset_unavailable'));
            } else {
                if ($brief instanceof ContentBrief) {
                    try {
                        app(PromptModuleResolver::class)->resolve($preset, [
                            'content_type' => (string) $brief->content_type,
                            'language_code' => (string) $brief->language_code,
                            'market_code' => (string) $brief->market_code,
                        ]);
                    } catch (InvalidArgumentException) {
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
