<?php

namespace App\Services\GeoFlow\LitbAssets;

final class LitbAssetManifest
{
    /** @var array<string, list<array{key:string,reason:string|null}>> */
    private array $entries = [
        'created' => [],
        'updated' => [],
        'skipped' => [],
        'conflicts' => [],
        'failed' => [],
    ];

    public function created(string $key): void { $this->record('created', $key); }

    public function updated(string $key): void { $this->record('updated', $key); }

    public function skipped(string $key): void { $this->record('skipped', $key); }

    public function conflict(string $key, string $reason): void { $this->record('conflicts', $key, $reason); }

    public function failed(string $key, string $reason): void { $this->record('failed', $key, $reason); }

    public function createdCount(): int { return count($this->entries['created']); }

    public function updatedCount(): int { return count($this->entries['updated']); }

    public function skippedCount(): int { return count($this->entries['skipped']); }

    public function conflictCount(): int { return count($this->entries['conflicts']); }

    public function failedCount(): int { return count($this->entries['failed']); }

    /** @return array<string, list<array{key:string,reason:string|null}>> */
    public function toArray(): array { return $this->entries; }

    private function record(string $bucket, string $key, ?string $reason = null): void
    {
        $this->entries[$bucket][] = ['key' => $key, 'reason' => $reason];
    }
}
