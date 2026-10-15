<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskGenerationEngineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_engine_defaults_to_legacy(): void
    {
        $task = Task::query()->create([
            'name' => 'Test task',
            'status' => 'paused',
        ]);

        $this->assertSame('legacy', $task->fresh()->generation_engine);
        $this->assertNull($task->fresh()->prompt_preset_id);
    }
}
