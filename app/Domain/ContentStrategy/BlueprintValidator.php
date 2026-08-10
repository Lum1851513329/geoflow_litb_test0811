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
            $this->add($errors, 'target_length.positive', 'Target length must be a positive integer.');
        }

        $sections = $blueprint['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $this->add($errors, 'sections.required', 'At least one section is required.');

            return $errors;
        }

        $keys = [];
        $minimumWordsTotal = 0;
        foreach ($sections as $index => $section) {
            $path = "sections.{$index}";
            if (! is_array($section)) {
                $this->add($errors, "{$path}.type", 'Each section must be an object.');

                continue;
            }

            $key = $section['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9-]*$/', $key) !== 1) {
                $this->add($errors, "{$path}.key.format", 'Section key must be a stable lowercase hyphenated key.');
            } elseif (isset($keys[$key])) {
                $this->add($errors, "{$path}.key.duplicate", 'Section keys must be unique.');
            } else {
                $keys[$key] = true;
            }

            $this->requiredText($section, 'heading', $errors, $path);
            $this->requiredText($section, 'purpose', $errors, $path);
            $this->questions($section, $path, $errors);

            $minimumWords = $section['minimum_words'] ?? null;
            if (! is_int($minimumWords) || $minimumWords <= 0) {
                $this->add($errors, "{$path}.minimum_words.positive", 'Section minimum words must be a positive integer.');
            } else {
                $minimumWordsTotal += $minimumWords;
            }

            if (! array_key_exists('cta_allowed', $section)) {
                $this->add($errors, "{$path}.cta_allowed.required", 'Each section requires one CTA policy.');
            } elseif (! is_bool($section['cta_allowed'])) {
                $this->add($errors, "{$path}.cta_allowed.boolean", 'Each section CTA policy must be boolean.');
            }

            $this->evidenceReferences($section, $path, $errors);
        }

        if (is_int($targetLength) && $targetLength > 0 && $minimumWordsTotal > $targetLength) {
            $this->add($errors, 'target_length.minimum_words_total', 'Target length must cover all section minimum words.');
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
            $this->add($errors, "{$path}.required", ucfirst(str_replace('_', ' ', $field)).' is required.');
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
            $this->add($errors, "{$path}.questions_to_answer.type", 'Questions must be a list.');

            return;
        }
        if ($questions === []) {
            $this->add($errors, "{$path}.questions_to_answer.required", 'Substantive sections require at least one question.');

            return;
        }

        foreach ($questions as $index => $question) {
            if (! is_string($question) || trim($question) === '') {
                $this->add($errors, "{$path}.questions_to_answer.{$index}.required", 'Questions must be non-empty strings.');
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
            $this->add($errors, "{$path}.required_fact_ids.type", 'Evidence references must be a list.');

            return;
        }

        foreach ($references as $index => $reference) {
            if (! is_string($reference) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $reference) !== 1) {
                $this->add($errors, "{$path}.required_fact_ids.{$index}.format", 'Evidence references must use stable identifiers.');
            }
        }
    }

    /** @param array<string, array<string>> $errors */
    private function add(array &$errors, string $field, string $message): void
    {
        $errors[$field][] = $message;
    }
}
