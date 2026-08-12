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

    public function test_gate_fails_closed_when_a_blueprint_section_has_no_heading(): void
    {
        $blueprint = $this->blueprint();
        unset($blueprint['sections'][0]['heading']);

        $result = $this->gate->inspect('', $blueprint, []);

        $this->assertFalse($result->passed);
        $this->assertContains('invalid_blueprint', array_column($result->issuesAsArray(), 'code'));
        $this->assertSame('sections.0.heading.required', $this->issue($result, 'invalid_blueprint')['section_key']);
    }

    public function test_gate_reports_each_blueprint_section_missing_from_markdown(): void
    {
        $result = $this->gate->inspect("## Sizing\n".$this->words(80), $this->blueprint(), []);

        $issues = array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'missing_section');

        $this->assertFalse($result->passed);
        $this->assertSame(['care'], array_values(array_column($issues, 'section_key')));
        $this->assertSame('Add an H2 heading named "Care" and include its planned guidance.', array_values($issues)[0]['repair_instruction']);
    }

    public function test_gate_matches_a_heading_that_extends_the_blueprint_heading_with_extra_words(): void
    {
        $markdown = '## Sizing for Every Foot Shape and Arch Type'."\n".$this->words(80)
            ."\n\n## Care and Maintenance Tips for Daily Runners\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertNotContains('missing_section', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_does_not_match_a_heading_that_merely_contains_the_blueprint_word_mid_string(): void
    {
        $result = $this->gate->inspect("## Resizing Your Order\n".$this->words(80)."\n\n## Care\n".$this->words(80), $this->blueprint(), []);

        $issues = array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'missing_section');

        $this->assertSame(['sizing'], array_values(array_column($issues, 'section_key')));
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

    public function test_fenced_and_commented_markdown_is_excluded_from_prose_and_placeholder_checks(): void
    {
        $markdown = "## Sizing\n~~~php\n{{product_name}}\n".$this->words(80)."\n~~~~\n<!-- {{comment_only}} -->\n\n## Care\n```\n{{care_variable}}\n".$this->words(80)."\n```\n<!-- hidden guidance -->";

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertContains('section_too_thin', array_column($result->issuesAsArray(), 'code'));
        $this->assertNotContains('unresolved_variable', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_unfinished_html_literals_inside_fenced_code_do_not_hide_later_rendered_sections(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['required_entities'] = ['Acme Run Lab'];
        $markdown = str_replace("\n\n## Care", "\n\n```html\n<!-- unfinished HTML literal\n```\n\n## Care", $this->substantiveArticle('Acme Run Lab'));

        $result = $this->gate->inspect($markdown, $blueprint, ['language_code' => 'en']);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->issuesAsArray());
    }

    public function test_html_comment_only_sections_do_not_supply_prose_or_placeholder_values(): void
    {
        $markdown = "## Sizing\n<!-- {{sizing_variable}} ".$this->words(80)." -->\n\n## Care\n<!-- {{care_variable}} ".$this->words(80).' -->';

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertCount(2, array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'section_too_thin'));
        $this->assertNotContains('unresolved_variable', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_unterminated_html_comments_hide_markdown_through_eof_without_hiding_prior_prose(): void
    {
        $markdown = "## Sizing\n".$this->words(80)."\n<!-- How should running shoes fit? {{hidden_variable}} ".trim(str_repeat('running shoes ', 20))."\n## Care\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), ['primary_keyword' => 'running shoes']);

        $this->assertNotContains('section_too_thin', array_column(array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['section_key'] === 'sizing'), 'code'));
        $this->assertContains('missing_section', array_column($result->issuesAsArray(), 'code'));
        $this->assertContains('missing_question_coverage', array_column($result->issuesAsArray(), 'code'));
        $this->assertNotContains('unresolved_variable', array_column($result->issuesAsArray(), 'code'));
        $this->assertNotContains('keyword_stuffing', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_visible_prose_placeholders_still_fail_after_non_rendered_markdown_is_removed(): void
    {
        $markdown = "## Sizing\n".$this->words(80)."\n\n## Care\n".$this->words(80)."\n{{visible_variable}}\n~~~\n{{fenced_variable}}\n~~~\n<!-- {{comment_variable}} -->";

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $variables = array_values(array_filter($result->issuesAsArray(), fn (array $issue): bool => $issue['code'] === 'unresolved_variable'));

        $this->assertCount(1, $variables);
        $this->assertStringContainsString('{{visible_variable}}', $variables[0]['message']);
    }

    public function test_zero_width_characters_do_not_inflate_word_counts_or_evade_keyword_and_duplicate_checks(): void
    {
        $thinBlueprint = $this->blueprint();
        $thinBlueprint['sections'][0]['minimum_words'] = 2;
        $thinBlueprint['sections'][1]['minimum_words'] = 2;
        $thin = $this->gate->inspect("## Sizing\nsmall\u{200B}guide\n\n## Care\nsmall\u{200B}guide", $thinBlueprint, []);

        $paragraph = 'Measure the heel and toe room before choosing a pair for a stable stride.';
        $duplicate = $this->gate->inspect("## Sizing\n".$this->words(80)."\n\n".str_replace('stable', "sta\u{200B}ble", $paragraph)."\n\n## Care\n".$this->words(80)."\n\n{$paragraph}", $this->blueprint(), []);
        $stuffed = $this->gate->inspect("## Sizing\n".trim(str_repeat("run\u{200B}ning shoes ", 20)).' '.$this->words(80)."\n\n## Care\n".$this->words(80), $this->blueprint(), ['primary_keyword' => 'running shoes']);

        $this->assertContains('section_too_thin', array_column($thin->issuesAsArray(), 'code'));
        $this->assertContains('duplicate_paragraph', array_column($duplicate->issuesAsArray(), 'code'));
        $this->assertContains('keyword_stuffing', array_column($stuffed->issuesAsArray(), 'code'));
    }

    public function test_default_ignorable_characters_cannot_hide_keywords_or_duplicate_paragraphs(): void
    {
        $paragraph = 'Measure the heel and toe room before choosing a pair for a stable stride.';
        $invisibleParagraph = "Meas\u{034F}ure the heel\u{200B} and toe\u{180B}\u{FE0F} room before cho\u{2060}osing a pair for a sta\u{FEFF}ble stride.";
        $duplicate = $this->gate->inspect("## Sizing\n".$this->words(80)."\n\n{$invisibleParagraph}\n\n## Care\nAlternate ".$this->words(80)."\n\n{$paragraph}", $this->blueprint(), []);
        $stuffed = $this->gate->inspect("## Sizing\n".trim(str_repeat("run\u{180B}\u{034F}ning shoes ", 20)).' '.$this->words(80)."\n\n## Care\n".$this->words(80), $this->blueprint(), ['primary_keyword' => 'running shoes']);

        $this->assertContains('duplicate_paragraph', array_column($duplicate->issuesAsArray(), 'code'));
        $this->assertContains('keyword_stuffing', array_column($stuffed->issuesAsArray(), 'code'));
    }

    public function test_canonical_accent_normalization_preserves_visible_accented_words(): void
    {
        $markdown = "## Sizing\n".trim(str_repeat("cafe\u{0301} fit ", 20)).' '.$this->words(80)."\n\n## Care\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), ['primary_keyword' => 'café fit']);

        $this->assertContains('keyword_stuffing', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_chinese_content_uses_han_characters_as_content_units_for_section_minimums(): void
    {
        $short = $this->gate->inspect("## 尺码\n".str_repeat('跑', 79), $this->chineseBlueprint(80), ['language_code' => 'zh']);
        $substantive = $this->gate->inspect("## 尺码\n".str_repeat('跑', 80), $this->chineseBlueprint(80), ['language_code' => 'zh']);

        $this->assertContains('section_too_thin', array_column($short->issuesAsArray(), 'code'));
        $this->assertTrue($substantive->passed);
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

    public function test_gate_enforces_prohibited_links_cta_and_market_constraints(): void
    {
        $markdown = $this->substantiveArticle('Acme Run Lab')
            ."\n\nBuy now at [our UK shop](https://example.test) for £99. Unsupported medical claims.";

        $result = $this->gate->inspect($markdown, $this->blueprint(), [
            'market_code' => 'US',
            'external_links_allowed' => false,
            'prohibited_information' => ['Unsupported medical claims'],
        ]);
        $codes = array_column($result->issuesAsArray(), 'code');

        $this->assertContains('prohibited_information', $codes);
        $this->assertContains('external_link_not_allowed', $codes);
        $this->assertContains('cta_not_allowed', $codes);
        $this->assertContains('market_mismatch', $codes);
    }

    public function test_external_link_gate_parses_protocol_relative_and_arbitrary_scheme_destinations(): void
    {
        $probes = [
            '[protocol relative](//outside.example/path)',
            '<a href="//outside.example/path">HTML protocol relative</a>',
            '[file transfer](ftp://outside.example/archive)',
            '<a href="mailto:outside@example.test">Email</a>',
            '<img src="data:image/svg+xml;base64,PHN2Zy8+" alt="embedded">',
            "[reference][outside]\n\n[outside]: javascript:alert(1)",
        ];

        foreach ($probes as $probe) {
            $result = $this->gate->inspect($this->substantiveArticle('Acme Run Lab')."\n\n{$probe}", $this->blueprint(), [
                'external_links_allowed' => false,
            ]);

            $this->assertContains('external_link_not_allowed', array_column($result->issuesAsArray(), 'code'), $probe);
        }
    }

    public function test_external_link_gate_allows_relative_anchors_and_non_link_prose(): void
    {
        $markdown = $this->substantiveArticle('Acme Run Lab')
            ."\n\n[Local sizing](/guides/sizing) [Care notes](../care) [Footnote](#fit-note). Readers learn how to buy safely by comparing fit evidence.";

        $result = $this->gate->inspect($markdown, $this->blueprint(), [
            'external_links_allowed' => false,
        ]);
        $codes = array_column($result->issuesAsArray(), 'code');

        $this->assertNotContains('external_link_not_allowed', $codes);
        $this->assertNotContains('cta_not_allowed', $codes);
    }

    public function test_cta_gate_blocks_explicit_markers_and_actionable_link_labels(): void
    {
        foreach ([
            '<a class="content-cta" href="/next">Explore sizing options</a>',
            '<aside data-cta></aside>',
            '[Start your free trial](/trial)',
            '[Purchase today](/products)',
            '[Shop the collection](/collection)',
            '<button>Reserve yours</button>',
            '<a href="/alerts">Subscribe for product alerts</a>',
            '[Sign up for early access](/signup)',
        ] as $probe) {
            $result = $this->gate->inspect($this->substantiveArticle('Acme Run Lab')."\n\n{$probe}", $this->blueprint(), []);

            $this->assertContains('cta_not_allowed', array_column($result->issuesAsArray(), 'code'), $probe);
        }
    }

    public function test_cta_gate_blocks_purchase_and_signup_prose_with_transactional_urgency(): void
    {
        foreach ([
            'Purchase today to secure your product before stock runs out.',
            'Order yours now while supplies last.',
            'Shop the collection before inventory runs out.',
            'Secure your pair today while stock lasts.',
            'Reserve yours now before availability ends.',
            'Subscribe today for product alerts.',
            'Sign up now for early access.',
        ] as $probe) {
            $result = $this->gate->inspect($this->substantiveArticle('Acme Run Lab')."\n\n{$probe}", $this->blueprint(), []);

            $this->assertContains('cta_not_allowed', array_column($result->issuesAsArray(), 'code'), $probe);
        }
    }

    public function test_cta_gate_allows_editorial_discussion_of_transaction_verbs(): void
    {
        $prose = 'Purchase decisions depend on fit evidence. Order processing usually takes two days. '
            .'Shop safety policies protect workers. Secure storage prevents credential leaks. '
            .'Reserve capacity is measured separately. Subscribers receive account notices. '
            .'Sign-up conversion is one metric in an analytics report. [Purchase criteria](/guides/criteria).';
        $result = $this->gate->inspect($this->substantiveArticle('Acme Run Lab')."\n\n{$prose}", $this->blueprint(), []);

        $this->assertNotContains('cta_not_allowed', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_enforces_total_target_length_tolerance(): void
    {
        $markdown = "## Sizing\n".$this->words(80)."\n\n## Care\n".$this->words(80);

        $result = $this->gate->inspect($markdown, $this->blueprint(), []);

        $this->assertContains('target_length_out_of_range', array_column($result->issuesAsArray(), 'code'));
    }

    public function test_gate_requires_reviewed_evidence_for_every_blueprint_fact(): void
    {
        $blueprint = $this->blueprint();
        $blueprint['sections'][0]['required_fact_ids'] = ['fit-study'];

        $missing = $this->gate->inspect($this->substantiveArticle('Acme Run Lab'), $blueprint, ['evidence_fact_ids' => []]);
        $covered = $this->gate->inspect($this->substantiveArticle('Acme Run Lab'), $blueprint, ['evidence_fact_ids' => ['fit-study']]);

        $this->assertContains('required_fact_uncovered', array_column($missing->issuesAsArray(), 'code'));
        $this->assertNotContains('required_fact_uncovered', array_column($covered->issuesAsArray(), 'code'));
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'working_title' => 'How to choose running shoes for new runners',
            'target_length' => 200,
            'sections' => [
                [
                    'key' => 'sizing',
                    'heading' => 'Sizing',
                    'purpose' => 'Explain the fitting criteria that make a first pair comfortable.',
                    'minimum_words' => 60,
                    'questions_to_answer' => ['How should running shoes fit?'],
                    'required_fact_ids' => [],
                    'cta_allowed' => false,
                ],
                [
                    'key' => 'care',
                    'heading' => 'Care',
                    'purpose' => 'Explain practical care that extends the useful life of running shoes.',
                    'minimum_words' => 60,
                    'questions_to_answer' => ['How do you care for running shoes?'],
                    'required_fact_ids' => [],
                    'cta_allowed' => false,
                ],
            ],
            'required_entities' => ['Acme Run Lab'],
        ];
    }

    /** @return array<string, mixed> */
    private function chineseBlueprint(int $minimumWords): array
    {
        return [
            'working_title' => '跑鞋尺码指南',
            'target_length' => $minimumWords,
            'sections' => [[
                'key' => 'sizing',
                'heading' => '尺码',
                'purpose' => '说明跑鞋尺码的选择方法。',
                'questions_to_answer' => [str_repeat('跑', $minimumWords)],
                'required_fact_ids' => [],
                'minimum_words' => $minimumWords,
                'cta_allowed' => false,
            ]],
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
