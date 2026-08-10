<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class PublishPromptModuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $module = $this->route('module');
        $version = $this->route('version');

        return $admin instanceof Admin
            && $module instanceof PromptModule
            && $version instanceof PromptModuleVersion
            && Gate::forUser($admin)->allows('publishVersion', [$module, $version]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
