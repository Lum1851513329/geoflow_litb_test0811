<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use DomainException;

final class EvidenceCitationRenderer
{
    public function __construct(private readonly ExternalSourcePolicy $sourcePolicy = new ExternalSourcePolicy) {}

    public function render(string $markdown, array $evidence): string
    {
        $byId = [];
        foreach ($evidence as $item) {
            $id = trim((string) ($item['fact_id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $item;
            }
        }
        $sources = [];
        $rendered = preg_replace_callback('/\[\[evidence:([A-Za-z0-9_.:-]+)\]\]/', function (array $match) use ($byId, &$sources): string {
            $id = $match[1];
            if (! isset($byId[$id])) {
                throw new DomainException("Unknown evidence citation: {$id}");
            }
            $url = trim((string) ($byId[$id]['source_url'] ?? ''));
            if ($url === '') {
                return '';
            }
            if (! $this->sourcePolicy->accepts($url, [])) {
                throw new DomainException("Unsafe evidence citation URL: {$id}");
            }
            $title = trim((string) ($byId[$id]['title'] ?? 'Source')) ?: 'Source';
            $label = str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $title);
            $link = "[{$label}]({$url})";
            $sources[$url] ??= $link;

            return $link;
        }, $markdown);
        if ($rendered === null) {
            throw new DomainException('Unable to render evidence citations.');
        }
        if ($sources !== []) {
            $rendered = rtrim($rendered)."\n\n## Sources\n\n".implode("\n", array_map(static fn (string $link): string => '- '.$link, $sources))."\n";
        }

        return $rendered;
    }
}
