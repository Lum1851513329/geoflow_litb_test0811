<?php

namespace Tests\Unit;

use Tests\TestCase;

class LitbLifestyleEditorialThemeCssTest extends TestCase
{
    public function test_theme_css_uses_the_litb_brand_palette_and_serif_display_font(): void
    {
        $css = file_get_contents(resource_path('views/theme/litb-lifestyle-editorial/assets/theme.css'));

        $this->assertStringContainsString('--ne-bg: #faf6f1;', $css);
        $this->assertStringContainsString('--ne-ink: #2b2622;', $css);
        $this->assertStringContainsString('--ne-red: #b8896f;', $css);
        $this->assertStringContainsString('"Playfair Display"', $css);
    }

    public function test_published_and_source_theme_css_stay_identical(): void
    {
        $source = file_get_contents(resource_path('views/theme/litb-lifestyle-editorial/assets/theme.css'));
        $published = file_get_contents(public_path('themes/litb-lifestyle-editorial/theme.css'));

        $this->assertSame($source, $published);
    }
}
