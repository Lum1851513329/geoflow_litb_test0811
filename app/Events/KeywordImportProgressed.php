<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KeywordImportProgressed implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $batchId,
        public readonly string $status,
        public readonly int $processedCount,
        public readonly int $totalCount,
        public readonly ?string $errorSummary = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.keyword-imports.'.$this->batchId);
    }

    public function broadcastAs(): string
    {
        return 'keyword-import.progressed';
    }

    /** @return array<string,int|string|null> */
    public function broadcastWith(): array
    {
        return [
            'batch_id' => $this->batchId,
            'status' => $this->status,
            'processed_count' => $this->processedCount,
            'total_count' => $this->totalCount,
            'error_summary' => $this->errorSummary,
        ];
    }
}
