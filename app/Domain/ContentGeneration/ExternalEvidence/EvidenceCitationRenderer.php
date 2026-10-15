<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

use DomainException;

final class EvidenceCitationRenderer
{
    public function __construct(private readonly ExternalSourcePolicy $sourcePolicy = new ExternalSourcePolicy) {}

    public function render(string $markdown, array $evidence, array $requiredSourceIds = []): string
    {
        $byId = [];
        foreach ($evidence as $item) {
            $id = trim((string) ($item['fact_id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $item;
            }
        }
        $sources = [];
        // Citations are surfaced only once, in the "## Sources" footer below —
        // not inline. The old behavior linked every [[evidence:id]] occurrence
        // in place, so a fact cited on N bullets produced the same
        // "[source title](url)" link repeated N times in the body, which read
        // as spammy repetition rather than a citation. The marker is still
        // validated and still feeds the footer; it's just removed from the
        // body instead of expanded into a link.
        $rendered = preg_replace_callback('/[ \t]*\[\[evidence:([A-Za-z0-9_.:-]+)\]\]/', function (array $match) use ($byId, &$sources): string {
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
            $sources[$url] ??= "[{$label}]({$url})";

            return '';
        }, $markdown);
        if ($rendered === null) {
            throw new DomainException('Unable to render evidence citations.');
        }
        // Marker removal can leave a stray space before punctuation
        // ("claim .") — tidy that without touching newlines/markdown structure.
        $rendered = preg_replace('/[ \t]+([.,;:!?])/', '$1', $rendered) ?? $rendered;
        foreach (array_values(array_unique(array_map('strval', $requiredSourceIds))) as $id) {
            if (! isset($byId[$id])) {
                throw new DomainException("Unknown required evidence source: {$id}");
            }
            $url = trim((string) ($byId[$id]['source_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            if (! $this->sourcePolicy->accepts($url, [])) {
                throw new DomainException("Unsafe required evidence source URL: {$id}");
            }
            $title = trim((string) ($byId[$id]['title'] ?? 'Source')) ?: 'Source';
            $label = str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $title);
            $sources[$url] ??= "[{$label}]({$url})";
        }
        if ($sources !== []) {
            $rendered = rtrim($rendered)."\n\n## Sources\n\n".implode("\n", array_map(static fn (string $link): string => '- '.$link, $sources))."\n";
        }

        return $rendered;
    }
}
