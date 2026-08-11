<?php

namespace App\Http\Requests\Admin;

use App\Domain\ContentStrategy\ContentStrategyService;
use App\Models\Admin;
use App\Models\ContentBrief;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

final class ConfirmContentStrategyRequest extends FormRequest
{
    private const LOW_CONFIDENCE = 0.7;

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
            'strategy' => ['required', 'array'],
            'strategy.primary_intent' => ['required', 'string', 'max:80'],
            'strategy.secondary_intents' => ['sometimes', 'array', 'max:20'],
            'strategy.secondary_intents.*' => ['string', 'max:100'],
            'strategy.decision_stage' => ['sometimes', 'nullable', 'string', 'max:80'],
            'strategy.content_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'strategy.content_goal' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'strategy.cta_strength' => ['sometimes', 'nullable', 'string', 'max:30'],
            'strategy.questions_to_answer' => ['sometimes', 'array', 'max:100'],
            'strategy.questions_to_answer.*' => ['string', 'max:1000'],
            'correction_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $brief = $this->route('brief');
            if (! $brief instanceof ContentBrief) {
                return;
            }
            $suggestion = app(ContentStrategyService::class)->suggest($brief);
            $suggestedIntent = (string) ($suggestion->strategy['primary_intent'] ?? '');
            $submittedIntent = (string) $this->input('strategy.primary_intent', '');
            if ($suggestion->confidence < self::LOW_CONFIDENCE
                && $submittedIntent !== $suggestedIntent
                && trim((string) $this->input('correction_reason')) === '') {
                $validator->errors()->add(
                    'correction_reason',
                    __('admin.content_strategy.validation.low_confidence_correction_reason'),
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $strategy = $this->input('strategy');
        if (is_string($strategy)) {
            try {
                $strategy = json_decode($strategy, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // The array rule reports malformed JSON without losing the submitted value.
            }
        }
        if (is_array($strategy)) {
            foreach (['secondary_intents', 'questions_to_answer'] as $field) {
                if (isset($strategy[$field]) && is_string($strategy[$field])) {
                    $strategy[$field] = $this->lines($strategy[$field]);
                }
            }
        }
        $this->merge(['strategy' => $strategy]);
    }

    /** @return list<string> */
    private function lines(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $value) ?: []),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
