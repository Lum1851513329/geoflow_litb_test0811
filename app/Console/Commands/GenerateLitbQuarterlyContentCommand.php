<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\GeoFlow\LitbAssets\LitbQuarterlyGenerationCoordinator;
use Illuminate\Console\Command;

class GenerateLitbQuarterlyContentCommand extends Command
{
    protected $signature = 'geoflow:litb-content:generate
        {--dry-run : Preview eligibility without dispatching any generation}
        {--limit=10 : Maximum briefs to process in this batch (1-25)}
        {--pillar= : Restrict to a single content pillar}
        {--brief= : Restrict to a single brief asset_key}
        {--model= : Restrict to a specific active AI model id}
        {--resume : Re-run to pick up briefs skipped in a previous batch (idempotent by default)}';

    protected $description = 'Preview or generate LITB quarterly content briefs in bounded, resumable batches.';

    public function handle(LitbQuarterlyGenerationCoordinator $coordinator): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 25) {
            $this->error('The --limit option must be between 1 and 25.');

            return self::FAILURE;
        }

        $filters = array_filter([
            'limit' => $limit,
            'pillar' => $this->option('pillar'),
            'brief' => $this->option('brief'),
            'model' => $this->option('model') !== null ? (int) $this->option('model') : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($this->option('dry-run')) {
            $preview = $coordinator->preview($filters);
            $this->info('Eligible: '.count($preview->eligible));
            $this->info('Skipped: '.count($preview->skipped));
            $this->summarizeSkipped($preview->skipped);
            foreach ($preview->eligible as $item) {
                $this->line('  eligible: '.$item['asset_key']);
            }

            return self::SUCCESS;
        }

        $admin = Admin::query()->where('status', 'active')->orderBy('id')->first();
        if ($admin === null) {
            $this->error('No active admin account is available to attribute this generation batch to.');

            return self::FAILURE;
        }

        $result = $coordinator->dispatch($filters, (int) $admin->id);
        $this->info('Dispatched: '.count($result->runIds));
        $this->info('Already running or completed (idempotent replay): '.count($result->existingRunIds));
        $this->info('Skipped: '.count($result->skipped));
        $this->summarizeSkipped($result->skipped);

        return self::SUCCESS;
    }

    /** @param list<array{asset_key: string, reason: string}> $skipped */
    private function summarizeSkipped(array $skipped): void
    {
        $byReason = [];
        foreach ($skipped as $item) {
            $reason = explode(':', $item['reason'], 2)[0];
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }
        foreach ($byReason as $reason => $count) {
            $this->line('  '.$reason.': '.$count);
        }
    }
}
