<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\ContentGenerationRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SubmitWorkBuddyOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $run = $this->route('run');

        // Same ability as adopting the run — only the person who can adopt
        // it should be able to supply what gets adopted.
        return $admin instanceof Admin
            && $run instanceof ContentGenerationRun
            && Gate::forUser($admin)->allows('adopt', $run);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'raw_output' => ['required', 'string', 'min:1'],
        ];
    }
}
