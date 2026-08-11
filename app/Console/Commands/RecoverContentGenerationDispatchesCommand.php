<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\ContentGenerationDispatcher;
use Illuminate\Console\Command;

final class RecoverContentGenerationDispatchesCommand extends Command
{
    protected $signature = 'geoflow:recover-content-generation-dispatches {--stale=300} {--limit=100}';

    protected $description = 'Enqueue stale content-generation runs that have no durable dispatch marker';

    public function handle(ContentGenerationDispatcher $dispatcher): int
    {
        $count = $dispatcher->recoverStale(
            max(1, (int) $this->option('stale')),
            max(1, min(1000, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered content-generation dispatches: %d', $count));

        return self::SUCCESS;
    }
}
