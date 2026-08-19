<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\KnowledgeGovernance\GovernedKnowledgeImporter;
use Illuminate\Console\Command;
use Throwable;

final class ImportGovernedKnowledgeCommand extends Command
{
    protected $signature = 'geoflow:knowledge:import-governed
        {manifest : Absolute or relative path to manifest.jsonl}
        {--dry-run : Validate the complete package without database writes}
        {--keyword-library=42 : Keyword library that owns all association targets}';

    protected $description = 'Validate and transactionally import a governed knowledge corpus';

    public function handle(GovernedKnowledgeImporter $importer): int
    {
        $library = filter_var($this->option('keyword-library'), FILTER_VALIDATE_INT);
        if ($library === false || $library < 1) {
            $this->components->error('The --keyword-library option must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import(
                (string) $this->argument('manifest'),
                (bool) $this->option('dry-run'),
                (int) $library,
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
