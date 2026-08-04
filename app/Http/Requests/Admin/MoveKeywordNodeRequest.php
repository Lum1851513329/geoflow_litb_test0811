<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\KeywordNode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class MoveKeywordNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('admin')->user() instanceof Admin;
    }

    /** @return array<string,array<int,string>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'exists:keyword_nodes,id'],
            'parent_id' => ['nullable', 'integer', 'exists:keyword_nodes,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $node = KeywordNode::query()->find($this->integer('node_id'));
            $parentId = $this->integer('parent_id');
            $parent = $parentId > 0 ? KeywordNode::query()->find($parentId) : null;
            if (! $node || ! $parent) {
                return;
            }
            if ((int) $node->id === (int) $parent->id) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.parent_self');

                return;
            }
            if ((int) $parent->taxonomy_id !== (int) $node->taxonomy_id) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.parent_cross_taxonomy');

                return;
            }
            if (str_starts_with((string) $parent->path, (string) $node->path.'/')) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.parent_descendant');

                return;
            }

            $subtreeDepth = (int) KeywordNode::query()
                ->where('taxonomy_id', $node->taxonomy_id)
                ->where(function ($query) use ($node): void {
                    $path = (string) $node->path;
                    $query->where('path', $path)->orWhereRaw("path LIKE ? ESCAPE '\\'", [$this->descendantPathPattern($path)]);
                })
                ->max('depth');
            $height = $subtreeDepth - (int) $node->depth;
            if ((int) $parent->depth + 1 + $height > $this->maximumDepth()) {
                $validator->errors()->add('parent_id', 'keyword_taxonomy.max_depth_exceeded');
            }
        }];
    }

    private function maximumDepth(): int
    {
        return max(0, (int) config('geoflow.keyword_taxonomy_max_depth', 5));
    }

    private function descendantPathPattern(string $path): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path).'/%';
    }
}
