<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\DeterministicQualityGate;
use Tests\TestCase;

class DeterministicQualityGateTest extends TestCase
{
    private DeterministicQualityGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new DeterministicQualityGate;
    }

    public function test_gate_rejects_thin_sections_and_unresolved_variables(): void
    {
        $result = $this->gate->inspect("## Sizing\nChoose the right size.\n{{product_name}}", $this->blueprint(), []);

        $this->assertFalse($result->passed);
        $this->assertContains('section_too_thin', array_column($result->issuesAsArray(), 'code'));
        $this->assertContains('unresolved_variable', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_reports_each_blueprint_section_missing_from_markdown(): void
    {
        $result = $this->gate->inspect("## Sizing\n".$this->words(80), $this->blueprint(), []);

        $issues = array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'missing_section');

        $this->assertFalse($result->passed);
        $this->assertSame(['care'], array_values(array_column($issues, 'section_key')));
        $this->assertSame('Add an H2 heading named "Care" and include its planned guidance.', array_values($issues)[0]['repair_instruction']);
    }

    public function test_gate_flags_duplicate_normalized_paragraphs(): void
    {
        $paragraph = 'Measure both feet at the end of the day and choose the larger measurement for a comfortable fit.';
        $markdown = "## Sizing\n".$this->words(70)."\n\n{$paragraph}\n\n## Care\n".$this->words(70)."\n\n".strtoupper($paragraph);

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertContains('duplicate_paragraph', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_rejects_keyword_density_above_the_threshold(): void
    {
        $markdown = "## Sizing\n".trim(str_repeat('running shoes ', 20)).' '.$this->words(80)."\n\n## Care\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), ['primary_keyword' => 'running shoes']);

        $this->assertFalse($result->passed);
        $this->assertContains('keyword_stuffing', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_reports_required_entities_that_are_not_mentioned(): void
    {
        $result = $this->gate->inspect($this->substantiveArticle(), $this->blueprint(), []);

        $this->assertContains('missing_required_entity', array_column($result->issuesAsArray(), 'code'));
        $this->assertSame('required_entities', $this->issue($result, 'missing_required_entity')['section_key']);
        $this->assertSame('Mention the required entity "Acme Run Lab" where its role is relevant.', $this->issue($result, 'missing_required_entity')['repair_instruction']);
    }

    public function test_gate_reports_blueprint_questions_without_section_coverage(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['sections'][0]['questions_to_answer'] = ['How do trail shoes drain water?'];

        $result = $this->gate->inspect($this->substantiveArticle(), $blueprint, []);

        $this->assertContains('missing_question_coverage', array_column($result->issuesAsArray(), 'code'));
        $this->assertSame('sizing', $this->issue($result, 'missing_question_coverage')['section_key']);
        $this->assertSame('Answer the planned question: "How do trail shoes drain water?".', $this->issue($result, 'missing_question_coverage')['repair_instruction']);
    }

    public function test_gate_reports_skipped_heading_levels(): void
    {
        $markdown = "## Sizing\n".$this->words(80)."\n\n#### Fit details\n".$this->words(20)."\n\n## Care\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertContains('heading_hierarchy', array_column($result->issuesAsArray(), 'code'));
        $this->assertSame('Use an H3 heading after H2 "Sizing"; do not jump directly to H4.', $this->issue($result, 'heading_hierarchy')['repair_instruction']);
    }

    public function test_gate_uses_a_script_based_language_mismatch_heuristic(): void
    {
        $markdown = "## Sizing\n".str_repeat('选择合适的跑鞋需要测量双脚并预留脚趾空间。', 8)."\n\n## Care\n".str_repeat('清洁鞋面后请自然晾干并定期检查鞋底。', 8);

        $result = $this->gate->inspect($markdown, $this->blueprint(), ['language_code' => 'en']);

        $this->assertFalse($result->passed);
        $this->assertContains('language_mismatch', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_passes_a_substantive_article_that_meets_the_blueprint(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['required_entities'] = ['Acme Run Lab'];

        $result = $this->gate->inspect($this->substantiveArticle('Acme Run Lab'), $blueprint, [
            'language_code' => 'en',
            'primary_keyword' => 'running shoes',
        ]);

        $this->assertTrue($result->passed);
        $this->assertSame(100, $result->score);
        $this->assertSame([], $result->issuesAsArray());
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'sections' => [
                [
                    'key' => 'sizing',
                    'heading' => 'Sizing',
                    'minimum_words' => 60,
                    'questions_to_answer' => ['How should running shoes fit?'],
                ],
                [
                    'key' => 'care',
                    'heading' => 'Care',
                    'minimum_words' => 60,
                    'questions_to_answer' => ['How do you care for running shoes?'],
                ],
            ],
            'required_entities' => ['Acme Run Lab'],
        ];
    }

    private function substantiveArticle(string $entity = ''): string
    {
        $entitySentence = $entity === '' ? '' : " {$entity} recommends recording this fit check before a first outdoor run.";

        return "## Sizing\n"
            .'Running shoes should fit with a thumb-width of room in front of the longest toe. Check the heel first: it should stay secure while you walk, jog, and turn. Try both shoes while wearing the socks you expect to use, because a thick sock changes the usable volume. Measure later in the day when feet are slightly larger, then choose the size that protects your toes on downhill routes. A roomy toe box and stable midfoot make it easier to adjust laces without numbing the forefoot.'
            .$entitySentence
            ."\n\n## Care\n"
            .'To care for running shoes, remove loose dirt with a soft brush after wet runs and let the pair air dry away from direct heat. Take out the insole so moisture can escape, then wipe the upper with a damp cloth instead of using a washing machine. Inspect the outsole for worn areas every few weeks, because uneven wear can signal that the cushioning no longer supports your stride. Rotate a second pair when possible so each shoe has time to dry fully between sessions.';
    }

    private function words(int $count): string
    {
        return trim(str_repeat('practical guidance supports a comfortable and confident decision ', $count));
    }

    /** @return array<string, mixed> */
    private function issue(object $result, string $code): array
    {
        foreach ($result->issuesAsArray() as $issue) {
            if ($issue['code'] === $code) {
                return $issue;
            }
        }

        $this->fail("Expected quality issue [{$code}] was not returned.");
    }
}
