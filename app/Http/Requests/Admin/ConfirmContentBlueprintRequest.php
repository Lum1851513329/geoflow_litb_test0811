<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentBrief;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmContentBlueprintRequest extends FormRequest
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
            'strategy_version_id' => ['nullable', 'integer', 'exists:content_strategy_versions,id'],
            'blueprint' => ['required', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $blueprint = $this->input('blueprint', $this->input('blueprint_json'));
        if (is_string($blueprint)) {
            try {
                $blueprint = json_decode($blueprint, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // The array rule reports malformed JSON without losing the submitted value.
            }
        }
        $this->merge(['blueprint' => $blueprint]);
    }
}
