<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\KnowledgeGovernance\LegacyKnowledgeMigrationService;
use Illuminate\Console\Command;
use Throwable;

final class MigrateLegacyKnowledgeCorpusCommand extends Command
{
    protected $signature = 'geoflow:knowledge:migrate-legacy
        {--batch= : knowledge_import_batches.id whose canonical documents legacy rows are matched against}
        {--dry-run : Report the migration plan and reference counts without writing to the database}
        {--backup-verified : Required in commit mode; asserts a verified database backup exists}';

    protected $description = 'Migrate legacy (pre-governance) knowledge_bases references onto their canonical governed replacement';

    public function handle(LegacyKnowledgeMigrationService $service): int
    {
        $batchId = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            $this->components->error('The --batch option must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $dryRun
                ? $service->plan($batchId)
                : $service->migrate($batchId, (bool) $this->option('backup-verified'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
