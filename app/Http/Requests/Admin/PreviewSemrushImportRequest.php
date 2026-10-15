<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use Illuminate\Foundation\Http\FormRequest;

final class PreviewSemrushImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'semrush_csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'distribution_channel_id' => ['required', 'integer', 'exists:distribution_channels,id'],
            // Optional: leave unset to let each row pick its own preset
            // from its auto-mapped category (SemrushTopicGapConverter::CATEGORY_PRESET_MAP).
            // Set it to force every row in the batch onto one preset instead.
            'prompt_preset_id' => ['nullable', 'integer', 'exists:prompt_presets,id'],
            'knowledge_brand_id' => ['required', 'integer', 'exists:knowledge_brands,id'],
            'knowledge_site_id' => ['nullable', 'integer', 'exists:knowledge_sites,id'],
            'knowledge_directory_id' => ['nullable', 'integer', 'exists:knowledge_directories,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $brandId = (int) $this->input('knowledge_brand_id');
            $siteId = $this->input('knowledge_site_id');
            if ($siteId !== null && $siteId !== '' && ! KnowledgeSite::whereKey((int) $siteId)->where('brand_id', $brandId)->exists()) {
                $validator->errors()->add('knowledge_site_id', '知识来源站点必须属于所选知识品牌。');
            }
            $directoryId = $this->input('knowledge_directory_id');
            if ($directoryId !== null && $directoryId !== '' && ! KnowledgeDirectory::whereKey((int) $directoryId)->where('brand_id', $brandId)->exists()) {
                $validator->errors()->add('knowledge_directory_id', '知识目录必须属于所选知识品牌。');
            }
        });
    }
}
