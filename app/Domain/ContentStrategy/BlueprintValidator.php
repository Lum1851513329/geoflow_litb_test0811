<?php

namespace App\Domain\ContentStrategy;

final class BlueprintValidator
{
    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, array<string>>
     */
    public function validate(array $blueprint): array
    {
        $errors = [];
        $this->requiredText($blueprint, 'working_title', $errors);
        $targetLength = $blueprint['target_length'] ?? null;
        if (! is_int($targetLength) || $targetLength <= 0) {
            $this->add($errors, 'target_length.positive', __('admin.content_strategy.blueprint_validation.target_length_positive'));
        }

        $sections = $blueprint['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $this->add($errors, 'sections.required', __('admin.content_strategy.blueprint_validation.sections_required'));

            return $errors;
        }

        $keys = [];
        $minimumWordsTotal = 0;
        foreach ($sections as $index => $section) {
            $path = "sections.{$index}";
            if (! is_array($section)) {
                $this->add($errors, "{$path}.type", __('admin.content_strategy.blueprint_validation.section_object'));

                continue;
            }

            $key = $section['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9-]*$/', $key) !== 1) {
                $this->add($errors, "{$path}.key.format", __('admin.content_strategy.blueprint_validation.section_key_format'));
            } elseif (isset($keys[$key])) {
                $this->add($errors, "{$path}.key.duplicate", __('admin.content_strategy.blueprint_validation.section_key_unique'));
            } else {
                $keys[$key] = true;
            }

            $this->requiredText($section, 'heading', $errors, $path);
            $this->requiredText($section, 'purpose', $errors, $path);
            $this->questions($section, $path, $errors);

            $minimumWords = $section['minimum_words'] ?? null;
            if (! is_int($minimumWords) || $minimumWords <= 0) {
                $this->add($errors, "{$path}.minimum_words.positive", __('admin.content_strategy.blueprint_validation.minimum_words_positive'));
            } else {
                $minimumWordsTotal += $minimumWords;
            }

            if (! array_key_exists('cta_allowed', $section)) {
                $this->add($errors, "{$path}.cta_allowed.required", __('admin.content_strategy.blueprint_validation.cta_required'));
            } elseif (! is_bool($section['cta_allowed'])) {
                $this->add($errors, "{$path}.cta_allowed.boolean", __('admin.content_strategy.blueprint_validation.cta_boolean'));
            }

            $this->evidenceReferences($section, $path, $errors);
        }

        if (is_int($targetLength) && $targetLength > 0 && $minimumWordsTotal > $targetLength) {
            $this->add($errors, 'target_length.minimum_words_total', __('admin.content_strategy.blueprint_validation.target_covers_sections'));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string>>  $errors
     */
    private function requiredText(array $values, string $field, array &$errors, string $prefix = ''): void
    {
        $path = $prefix === '' ? $field : "{$prefix}.{$field}";
        if (! is_string($values[$field] ?? null) || trim((string) $values[$field]) === '') {
            $this->add($errors, "{$path}.required", __('admin.content_strategy.blueprint_validation.text_required', [
                'attribute' => __('admin.content_strategy.fields.'.$field),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, array<string>>  $errors
     */
    private function questions(array $section, string $path, array &$errors): void
    {
        $questions = $section['questions_to_answer'] ?? null;
        if (! is_array($questions) || ! array_is_list($questions)) {
            $this->add($errors, "{$path}.questions_to_answer.type", __('admin.content_strategy.blueprint_validation.questions_list'));

            return;
        }
        if ($questions === []) {
            $this->add($errors, "{$path}.questions_to_answer.required", __('admin.content_strategy.blueprint_validation.questions_required'));

            return;
        }

        foreach ($questions as $index => $question) {
            if (! is_string($question) || trim($question) === '') {
                $this->add($errors, "{$path}.questions_to_answer.{$index}.required", __('admin.content_strategy.blueprint_validation.question_non_empty'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, array<string>>  $errors
     */
    private function evidenceReferences(array $section, string $path, array &$errors): void
    {
        $references = $section['required_fact_ids'] ?? [];
        if (! is_array($references) || ! array_is_list($references)) {
            $this->add($errors, "{$path}.required_fact_ids.type", __('admin.content_strategy.blueprint_validation.evidence_list'));

            return;
        }

        foreach ($references as $index => $reference) {
            if (! is_string($reference) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $reference) !== 1) {
                $this->add($errors, "{$path}.required_fact_ids.{$index}.format", __('admin.content_strategy.blueprint_validation.evidence_identifier'));
            }
        }
    }

    /** @param array<string, array<string>> $errors */
    private function add(array &$errors, string $field, string $message): void
    {
        $errors[$field][] = $message;
    }
}
