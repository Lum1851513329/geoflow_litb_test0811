<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\MoveKeywordNodeRequest;
use App\Http\Requests\Admin\StoreKeywordNodeRequest;
use App\Models\Admin;
use App\Models\KeywordNode;
use App\Models\KeywordTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Tests\TestCase;

class KeywordTaxonomyRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_guard_authorizes_both_requests_and_valid_payloads_pass_request_validation(): void
    {
        $taxonomy = $this->taxonomy();
        $node = $this->node($taxonomy, 'root', 'root', 0);
        $store = $this->storeRequest(['taxonomy_id' => $taxonomy->id, 'name' => 'Root', 'sort_order' => 0]);
        $move = $this->moveRequest(['node_id' => $node->id, 'parent_id' => null, 'sort_order' => 0]);

        $this->assertFalse($store->authorize());
        $this->assertFalse($move->authorize());

        $this->actingAs($this->admin(), 'admin');

        $this->assertTrue($store->authorize());
        $this->assertTrue($move->authorize());
        $this->assertTrue($this->validationErrors($store)->isEmpty());
        $this->assertTrue($this->validationErrors($move)->isEmpty());
    }

    public function test_store_request_reports_stable_cross_taxonomy_and_depth_errors(): void
    {
        config()->set('geoflow.keyword_taxonomy_max_depth', 0);
        $taxonomy = $this->taxonomy();
        $otherTaxonomy = $this->taxonomy('Other');
        $foreignParent = $this->node($otherTaxonomy, 'foreign', 'foreign', 0);
        $localParent = $this->node($taxonomy, 'local', 'local', 0);

        $crossTaxonomy = $this->validationErrors($this->storeRequest([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $foreignParent->id,
            'name' => 'Child',
        ]));
        $tooDeep = $this->validationErrors($this->storeRequest([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $localParent->id,
            'name' => 'Child',
        ]));

        $this->assertSame(['keyword_taxonomy.parent_cross_taxonomy'], $crossTaxonomy->get('parent_id'));
        $this->assertSame(['keyword_taxonomy.max_depth_exceeded'], $tooDeep->get('parent_id'));
    }

    public function test_move_request_reports_stable_cycle_and_depth_errors(): void
    {
        config()->set('geoflow.keyword_taxonomy_max_depth', 1);
        $taxonomy = $this->taxonomy();
        $root = $this->node($taxonomy, 'root', 'root', 0);
        $child = $this->node($taxonomy, 'child', 'root/child', 1, $root);
        $grandchild = $this->node($taxonomy, 'grandchild', 'root/child/grandchild', 2, $child);
        $destination = $this->node($taxonomy, 'destination', 'destination', 0);

        $cycle = $this->validationErrors($this->moveRequest([
            'node_id' => $root->id,
            'parent_id' => $child->id,
            'sort_order' => 0,
        ]));
        $tooDeep = $this->validationErrors($this->moveRequest([
            'node_id' => $child->id,
            'parent_id' => $destination->id,
            'sort_order' => 0,
        ]));

        $this->assertSame(['keyword_taxonomy.parent_descendant'], $cycle->get('parent_id'));
        $this->assertSame(['keyword_taxonomy.max_depth_exceeded'], $tooDeep->get('parent_id'));
        $this->assertSame($grandchild->id, $grandchild->fresh()->id);
    }

    /** @param array<string,mixed> $data */
    private function storeRequest(array $data): StoreKeywordNodeRequest
    {
        return StoreKeywordNodeRequest::create('/', 'POST', $data);
    }

    /** @param array<string,mixed> $data */
    private function moveRequest(array $data): MoveKeywordNodeRequest
    {
        return MoveKeywordNodeRequest::create('/', 'POST', $data);
    }

    private function validationErrors(FormRequest $request): MessageBag
    {
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $after) {
            $validator->after($after);
        }
        $validator->fails();

        return $validator->errors();
    }

    private function taxonomy(string $name = 'Apparel'): KeywordTaxonomy
    {
        return KeywordTaxonomy::query()->create([
            'name' => $name,
            'slug' => strtolower($name),
        ]);
    }

    private function node(KeywordTaxonomy $taxonomy, string $slug, string $path, int $depth, ?KeywordNode $parent = null): KeywordNode
    {
        return KeywordNode::query()->create([
            'taxonomy_id' => $taxonomy->id,
            'parent_id' => $parent?->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'path' => $path,
            'depth' => $depth,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'taxonomy-admin',
            'email' => 'taxonomy-admin@example.test',
            'password' => 'password',
            'display_name' => 'Taxonomy Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
