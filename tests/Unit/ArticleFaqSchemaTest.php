<?php

namespace Tests\Unit;

use App\Support\Site\ArticleFaqSchema;
use Tests\TestCase;

class ArticleFaqSchemaTest extends TestCase
{
    public function test_it_extracts_question_answer_pairs_from_the_faq_section(): void
    {
        $markdown = <<<'MD'
## Direct answer and decision summary
Some intro content.

## Frequently asked questions

### Is LightInTheBox an official online store?
Yes, LightInTheBox is an official online retail platform.

### How do I contact LightInTheBox?
Use the official support channel listed on the site.
MD;

        $pairs = ArticleFaqSchema::extract($markdown);

        $this->assertCount(2, $pairs);
        $this->assertSame('Is LightInTheBox an official online store?', $pairs[0]['question']);
        $this->assertSame('Yes, LightInTheBox is an official online retail platform.', $pairs[0]['answer']);
        $this->assertSame('How do I contact LightInTheBox?', $pairs[1]['question']);
    }

    public function test_it_returns_empty_when_there_is_no_faq_section(): void
    {
        $this->assertSame([], ArticleFaqSchema::extract("## Direct answer\nNo FAQ here."));
    }
}
