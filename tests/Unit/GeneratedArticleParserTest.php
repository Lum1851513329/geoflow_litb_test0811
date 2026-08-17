<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use PHPUnit\Framework\TestCase;

final class GeneratedArticleParserTest extends TestCase
{
    private GeneratedArticleParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GeneratedArticleParser;
    }

    public function test_extracts_a_leading_h1_title_and_strips_it_from_the_content(): void
    {
        $result = $this->parser->extractTitle("# How to Choose Running Shoes\n\n## Sizing\nBody text.");

        $this->assertSame('How to Choose Running Shoes', $result['title']);
        $this->assertSame("## Sizing\nBody text.", $result['content']);
    }

    public function test_returns_null_title_when_there_is_no_leading_h1(): void
    {
        $markdown = "## Sizing\nBody text.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
        $this->assertSame($markdown, $result['content']);
    }

    public function test_does_not_match_a_leading_h2_as_a_title(): void
    {
        $markdown = "## Not a title\nBody text.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
    }

    public function test_trims_trailing_hashes_and_whitespace_from_the_title(): void
    {
        $result = $this->parser->extractTitle("#   How to Choose Running Shoes   #\n\nBody.");

        $this->assertSame('How to Choose Running Shoes', $result['title']);
    }

    public function test_returns_null_title_for_an_empty_h1(): void
    {
        $markdown = "# \n\nBody.";

        $result = $this->parser->extractTitle($markdown);

        $this->assertNull($result['title']);
        $this->assertSame($markdown, $result['content']);
    }
}
