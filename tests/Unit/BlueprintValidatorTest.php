<?php

namespace Tests\Unit;

use App\Domain\ContentStrategy\BlueprintValidator;
use Tests\TestCase;

class BlueprintValidatorTest extends TestCase
{
    public function test_valid_blueprint_has_no_errors(): void
    {
        $this->assertSame([], app(BlueprintValidator::class)->validate($this->blueprint()));
    }

    public function test_sections_require_stable_unique_keys_and_non_empty_purposes(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['sections'][0]['key'] = 'Not stable';
        $blueprint['sections'][] = $blueprint['sections'][1];
        $blueprint['sections'][1]['purpose'] = '  ';

        $errors = app(BlueprintValidator::class)->validate($blueprint);

        $this->assertArrayHasKey('sections.0.key.format', $errors);
        $this->assertArrayHasKey('sections.2.key.duplicate', $errors);
        $this->assertArrayHasKey('sections.1.purpose.required', $errors);
    }

    public function test_substantive_sections_require_questions_positive_word_targets_and_one_boolean_cta_policy(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['target_length'] = 0;
        $blueprint['sections'][0]['questions_to_answer'] = [];
        $blueprint['sections'][0]['minimum_words'] = 0;
        $blueprint['sections'][0]['cta_allowed'] = 'yes';

        $errors = app(BlueprintValidator::class)->validate($blueprint);

        $this->assertArrayHasKey('target_length.positive', $errors);
        $this->assertArrayHasKey('sections.0.questions_to_answer.required', $errors);
        $this->assertArrayHasKey('sections.0.minimum_words.positive', $errors);
        $this->assertArrayHasKey('sections.0.cta_allowed.boolean', $errors);
    }

    public function test_target_length_must_cover_section_minimums_and_evidence_references_must_have_stable_shape(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['target_length'] = 400;
        $blueprint['sections'][0]['required_fact_ids'] = ['invalid reference'];

        $errors = app(BlueprintValidator::class)->validate($blueprint);

        $this->assertArrayHasKey('target_length.minimum_words_total', $errors);
        $this->assertArrayHasKey('sections.0.required_fact_ids.0.format', $errors);
    }

    public function test_sections_require_list_shaped_questions_and_an_explicit_cta_policy(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['sections'][0]['questions_to_answer'] = ['first' => 'How should running shoes fit?'];
        unset($blueprint['sections'][0]['cta_allowed']);

        $errors = app(BlueprintValidator::class)->validate($blueprint);

        $this->assertArrayHasKey('sections.0.questions_to_answer.type', $errors);
        $this->assertArrayHasKey('sections.0.cta_allowed.required', $errors);
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'working_title' => 'How to choose running shoes for new runners',
            'target_length' => 900,
            'sections' => [
                [
                    'key' => 'fit-basics',
                    'heading' => 'Start with fit',
                    'purpose' => 'Explain the fitting criteria that make a first pair comfortable.',
                    'questions_to_answer' => ['How should running shoes fit?'],
                    'required_fact_ids' => ['knowledge-chunk:fit-basics'],
                    'minimum_words' => 350,
                    'cta_allowed' => false,
                ],
                [
                    'key' => 'choose-by-use',
                    'heading' => 'Match shoes to intended use',
                    'purpose' => 'Connect terrain and training goals to practical shoe choices.',
                    'questions_to_answer' => ['What shoes suit road running?'],
                    'required_fact_ids' => [],
                    'minimum_words' => 350,
                    'cta_allowed' => true,
                ],
            ],
        ];
    }
}
