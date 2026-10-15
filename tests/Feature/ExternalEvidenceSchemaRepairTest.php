<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExternalEvidenceSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrective_migration_repairs_a_partially_applied_external_evidence_policy_migration(): void
    {
        Schema::table('content_generation_runs', function ($table): void {
            $table->dropColumn('external_evidence_policy_snapshot');
        });

        $this->assertFalse(Schema::hasColumn('content_generation_runs', 'external_evidence_policy_snapshot'));

        $migration = require database_path('migrations/2026_08_27_000001_repair_external_evidence_policy_snapshot.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('content_generation_runs', 'external_evidence_policy_snapshot'));
    }
}
