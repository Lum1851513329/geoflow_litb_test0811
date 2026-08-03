<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Keywords\KeywordColumnMapper;
use PHPUnit\Framework\TestCase;

class KeywordColumnMapperTest extends TestCase
{
    public function test_it_suggests_only_known_aliases_for_search_and_ad_columns(): void
    {
        $mapping = (new KeywordColumnMapper)->suggest([
            'query',
            'clicks',
            'impressions',
            'ctr',
            'average_position',
            'country',
            'language',
            'date_range',
            'cost',
            'conversions',
            'campaign_note',
        ], 'google_ads');

        $this->assertSame([
            'query' => 'keyword',
            'clicks' => 'clicks',
            'impressions' => 'impressions',
            'ctr' => 'ctr',
            'average_position' => 'position',
            'country' => 'country',
            'language' => 'language',
            'date_range' => 'date_range',
            'cost' => 'cost',
            'conversions' => 'conversions',
        ], $mapping);
    }

    public function test_it_leaves_unknown_columns_unmapped_and_prefers_a_keyword_alias(): void
    {
        $mapping = (new KeywordColumnMapper)->suggest(['keyword', 'search_term', 'notes'], 'google_search_console');

        $this->assertSame(['keyword' => 'keyword'], $mapping);
        $this->assertArrayNotHasKey('notes', $mapping);
        $this->assertArrayNotHasKey('search_term', $mapping);
    }
}
