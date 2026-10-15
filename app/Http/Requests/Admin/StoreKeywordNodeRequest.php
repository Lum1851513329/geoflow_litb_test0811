<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\KeywordNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class StoreKeywordNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('admin')->user() instanceof Admin;
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return [
            'taxonomy_id' => ['required', 'integer', 'exists:keyword_taxonomies,id'],
            'parent_id' => ['nullable', 'integer', 'exists:keyword_nodes,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $parentId = $this->integer('parent_id');
            if ($parentId < 1) {
                return;
            }
            $parent = KeywordNode::query()->find($parentId);
            if (! $parent) {
                return;
            }
            if ((int) $parent->taxonomy_id !== $this->integer('taxonomy_id')) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.parent_cross_taxonomy');

                return;
            }
            if ((int) $parent->depth + 1 > $this->maximumDepth()) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.max_depth_exceeded');
            }
        }];
    }

    private function maximumDepth(): int
    {
        return max(0, (int) config('geoflow.keyword_taxonomy_max_depth', 5));
    }
}
