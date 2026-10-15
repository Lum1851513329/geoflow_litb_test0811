<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentGenerationRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AdoptContentGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $run = $this->route('run');

        return $admin instanceof Admin
            && $run instanceof ContentGenerationRun
            && Gate::forUser($admin)->allows('adopt', $run);
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
