<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\PromptModule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePromptModuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $module = $this->route('module');

        return $admin instanceof Admin
            && $module instanceof PromptModule
            && Gate::forUser($admin)->allows('createVersion', $module);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:500000'],
            'variable_contract' => ['present', 'array'],
            'output_contract' => ['nullable', 'array'],
            'parameter_defaults' => ['nullable', 'array'],
            'change_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->decodedJsonFields([
            'variable_contract',
            'output_contract',
            'parameter_defaults',
        ]));
    }

    /** @param list<string> $fields @return array<string, mixed> */
    protected function decodedJsonFields(array $fields): array
    {
        $decoded = [];
        foreach ($fields as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                $decoded[$field] = null;

                continue;
            }

            try {
                $decoded[$field] = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded[$field] = $value;
            }
        }

        return $decoded;
    }
}
