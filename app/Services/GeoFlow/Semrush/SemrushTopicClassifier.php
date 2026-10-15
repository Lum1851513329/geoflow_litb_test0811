<?php

namespace App\Services\GeoFlow\Semrush;

/** Deterministically identifies the evidence scope and broad content type of a Semrush topic. */
final class SemrushTopicClassifier
{
    /** @param array<string,mixed> $row @param array<string,mixed> $brandProfile @return array<string,mixed> */
    public function classify(array $row, array $brandProfile): array
    {
        $topic = trim((string) ($row['topic'] ?? $row['topic_name'] ?? $row['name'] ?? ''));
        $keyword = trim((string) ($row['primary_keyword'] ?? $row['prompt'] ?? ''));
        $text = mb_strtolower(trim($topic.' '.$keyword));
        $aliases = array_values(array_filter(array_map(static fn (mixed $alias): string => mb_strtolower(trim((string) $alias)), (array) ($brandProfile['aliases'] ?? []))));
        $brandEntity = false;
        foreach ($aliases as $alias) {
            if ($alias !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($alias, '/').'(?!(?:[a-z0-9]))/i', $text) === 1) {
                $brandEntity = true;
                break;
            }
        }

        $contentType = $this->contentType($text);
        $decisionGuidance = preg_match('/\b(choose|which|best|compare|comparison|vs|versus|buy|buying|recommend|options|criteria|guide|how to)\b/i', $text) === 1;
        $policy = preg_match('/\b(shipping|delivery|tracking|return|refund|payment|warranty|support|customer service|official policy)\b/i', $text) === 1;
        $reasonCodes = [];
        if ($brandEntity) {
            $reasonCodes[] = 'brand_entity';
        }
        if ($decisionGuidance) {
            $reasonCodes[] = 'decision_guidance';
        }
        if ($policy) {
            $reasonCodes[] = 'policy_or_service';
        }

        $mode = $brandEntity ? ($decisionGuidance && ! $policy ? 'mixed' : 'brand') : 'general';
        if ($policy && $brandEntity) {
            $contentType = 'brand_policy';
            $mode = 'brand';
        }

        return [
            'knowledge_mode' => $mode,
            'content_type' => $contentType,
            'category_hint' => $this->categoryHint($contentType),
            'directory_required' => $mode !== 'general',
            'reason_codes' => $reasonCodes,
        ];
    }

    private function contentType(string $text): string
    {
        if (preg_match('/\b(vs|versus|compare|comparison|alternative|similar to|dupe)\b/i', $text) === 1) {
            return 'comparison';
        }
        if (preg_match('/\b(shipping|delivery|tracking|return|refund|payment|warranty|support|customer service|official policy)\b/i', $text) === 1) {
            return 'brand_policy';
        }
        if (preg_match('/\b(industry|market|marketplace|retailers|retail industry|trend|outlook|statistics|forecast)\b/i', $text) === 1) {
            return 'industry';
        }
        if (preg_match('/\b(what is|definition|meaning|history|explained|how does|science|educational|facts)\b/i', $text) === 1) {
            return 'educational';
        }

        return 'shopping_advice';
    }

    private function categoryHint(string $contentType): string
    {
        return match ($contentType) {
            'comparison' => 'comparison',
            'brand_policy' => 'brand_policy',
            'industry' => 'industry',
            'educational' => 'educational',
            default => 'shopping_advice',
        };
    }
}
