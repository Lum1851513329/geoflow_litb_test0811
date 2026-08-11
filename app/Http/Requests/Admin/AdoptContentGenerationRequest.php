<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentGenerationRun;
use Illuminate\Foundation\Http\FormRequest;

final class AdoptContentGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin
            && $this->route('run') instanceof ContentGenerationRun;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'excerpt' => ['nullable', 'string', 'max:5000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'author_id' => ['required', 'integer', 'exists:authors,id'],
        ];
    }
}
