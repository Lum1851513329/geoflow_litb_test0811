<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminContentCenterNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_content_center_exposes_prompt_navigation(): void
    {
        config()->set([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);
        $admin = Admin::query()->create([
            'username' => 'content-navigation-admin',
            'password' => 'secret-123',
            'email' => 'content-navigation@example.test',
            'display_name' => 'Content Navigation Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'en'])
            ->get(route('admin.content-briefs.index'))
            ->assertOk()
            ->assertSee(route('admin.prompt-modules.index'), false)
            ->assertSee(route('admin.prompt-presets.index'), false);
    }
}
