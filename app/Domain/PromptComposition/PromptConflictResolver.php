<?php

namespace App\Domain\PromptComposition;

final class PromptConflictResolver
{
    /**
     * @param list<ResolvedPromptModule> $modules
     * @param array<string, mixed> $variables
     * @return array{variables: array<string, mixed>, decisions: list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>}
     */
    public function resolve(array $modules, array $variables, PromptContext $context): array
    {
        $decisions = [];
        $versionIds = array_values(array_map(
            fn (ResolvedPromptModule $module): int => $module->versionId,
            $modules,
        ));

        if (($variables['cta_allowed'] ?? true) === false && $this->isRequested($variables['requested_cta'] ?? null)) {
            $loser = $variables['requested_cta'];
            $variables['requested_cta'] = 'none';
            $decisions[] = $this->decision(
                'cta_disabled_by_hard_constraint',
                'cta_allowed=false',
                'requested_cta='.$this->value($loser),
                'A hard CTA prohibition takes precedence over the requested CTA strength.',
                $versionIds,
            );
        }

        if (($variables['external_links_allowed'] ?? true) === false && $this->isRequested($variables['requested_external_links'] ?? null)) {
            $loser = $variables['requested_external_links'];
            $variables['requested_external_links'] = false;
            $decisions[] = $this->decision(
                'external_links_disabled_by_hard_constraint',
                'external_links_allowed=false',
                'requested_external_links='.$this->value($loser),
                'A hard external-link prohibition takes precedence over link preferences.',
                $versionIds,
            );
        }

        if (($variables['evidence_required'] ?? false) === true && ($variables['allow_unsupported_claims'] ?? false) === true) {
            $variables['allow_unsupported_claims'] = false;
            $decisions[] = $this->decision(
                'evidence_requirement_enforced',
                'evidence_required=true',
                'allow_unsupported_claims=true',
                'Truthfulness and evidence requirements take precedence over permissive claim settings.',
                $versionIds,
            );
        }

        if (isset($variables['requested_language']) && $variables['requested_language'] !== $context->language) {
            $loser = $variables['requested_language'];
            $variables['requested_language'] = $context->language;
            $decisions[] = $this->decision(
                'language_enforced',
                'language='.$context->language,
                'requested_language='.$this->value($loser),
                'The confirmed target language takes precedence over a writing preference.',
                $versionIds,
            );
        }

        if (isset($variables['required_output_format'], $variables['output_format'])
            && $variables['required_output_format'] !== $variables['output_format']) {
            $loser = $variables['output_format'];
            $variables['output_format'] = $variables['required_output_format'];
            $decisions[] = $this->decision(
                'output_format_enforced',
                'required_output_format='.$this->value($variables['required_output_format']),
                'output_format='.$this->value($loser),
                'The required output contract takes precedence over a format preference.',
                $versionIds,
            );
        }

        $targetLength = $context->blueprint['target_length'] ?? null;
        if (is_int($targetLength) && isset($variables['requested_target_length'])
            && $variables['requested_target_length'] !== $targetLength) {
            $loser = $variables['requested_target_length'];
            $variables['requested_target_length'] = $targetLength;
            $decisions[] = $this->decision(
                'target_length_enforced',
                'blueprint.target_length='.$targetLength,
                'requested_target_length='.$this->value($loser),
                'The confirmed content blueprint takes precedence over a target-length preference.',
                $versionIds,
            );
        }

        return ['variables' => $variables, 'decisions' => $decisions];
    }

    private function isRequested(mixed $value): bool
    {
        return ! in_array($value, [null, false, '', 'none', 'off', 'disabled'], true);
    }

    private function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<int> $versionIds
     * @return array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}
     */
    private function decision(string $code, string $winner, string $loser, string $reason, array $versionIds): array
    {
        return [
            'code' => $code,
            'winner' => $winner,
            'loser' => $loser,
            'reason' => $reason,
            'affected_module_version_ids' => $versionIds,
        ];
    }
}
