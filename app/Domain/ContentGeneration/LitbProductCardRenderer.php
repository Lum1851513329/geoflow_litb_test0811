<?php

namespace App\Domain\ContentGeneration;

/**
 * Render litb.product-card.v1 products as the card markup already live on the test site.
 *
 * Markup mirrors the published article at
 * /2026/08/13/cocktail-vs-evening-dresses-understanding-the-difference/ so the existing
 * theme CSS styles these without change. Do not rename the BEM classes.
 *
 * Per docs/superpowers/specs/2026-08-25-wordpress-product-card-design.md:
 *  - only `approved` products render;
 *  - a card needs url, title, image, price, currency, market and a capture timestamp;
 *  - price is a timestamped snapshot, never a permanent claim, so the freshness note is
 *    mandatory and carries the market/currency and the date checked;
 *  - an individually invalid product is omitted, and an empty group renders nothing at all
 *    rather than leaving an empty container.
 */
final class LitbProductCardRenderer
{
    public const SCHEMA_VERSION = 'litb.product-card.v1';

    /**
     * @param  array<string,mixed>  $payload  a litb.product-card.v1 document
     */
    public function render(array $payload): string
    {
        $products = array_values(array_filter(
            (array) ($payload['products'] ?? []),
            fn ($product): bool => is_array($product) && $this->isRenderable($product)
        ));
        if ($products === []) {
            return '';
        }

        $cards = implode('', array_map(fn (array $product): string => $this->card($product), $products));

        return '<div class="litb-product-card-group">'.$cards.'</div>';
    }

    /** @param array<string,mixed> $product */
    private function isRenderable(array $product): bool
    {
        if (($product['status'] ?? '') !== 'approved') {
            return false;
        }
        foreach (['product_url', 'title', 'image_url', 'current_price', 'currency', 'captured_at'] as $field) {
            if (trim((string) ($product[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $product */
    private function card(array $product): string
    {
        $url = $this->attr((string) $product['product_url']);
        $title = $this->text((string) $product['title']);
        $image = $this->attr((string) $product['image_url']);
        $alt = $this->attr((string) ($product['image_alt'] ?? $product['title']));
        $symbol = $this->currencySymbol((string) $product['currency']);

        $html = '<article class="litb-product-card">';
        $html .= '<a class="litb-product-card__media" href="'.$url.'" target="_blank" rel="noopener">';
        $html .= '<img src="'.$image.'" alt="'.$alt.'" loading="lazy" decoding="async"></a>';
        $html .= '<div class="litb-product-card__body">';

        $eyebrow = trim((string) ($product['context_label'] ?? $product['badge'] ?? ''));
        if ($eyebrow !== '') {
            $html .= '<p class="litb-product-card__eyebrow">'.$this->text($eyebrow).'</p>';
        }

        $html .= '<div class="litb-product-card__title"><a href="'.$url.'" target="_blank" rel="noopener" title="'.$this->attr((string) $product['title']).'">'.$title.'</a></div>';

        $html .= '<div class="litb-product-card__price-row"><span class="litb-product-card__price">'.$symbol.$this->text((string) $product['current_price']).'</span>';
        $original = trim((string) ($product['original_price'] ?? ''));
        if ($original !== '' && $original !== (string) $product['current_price']) {
            $html .= '<span class="litb-product-card__was">'.$symbol.$this->text($original).'</span>';
        }
        $html .= '</div>';

        $rating = trim((string) ($product['rating'] ?? ''));
        if ($rating !== '' && (float) $rating > 0) {
            $stars = str_repeat('★', max(1, min(5, (int) round((float) $rating))));
            $html .= '<div class="litb-product-card__rating"><strong>'.$stars.'</strong> '.$this->text($rating).'</div>';
        }

        $html .= '<p class="litb-product-card__note">'.$this->text($this->freshnessNote($product)).'</p>';
        $html .= '<a class="litb-product-card__action" href="'.$url.'" target="_blank" rel="noopener">View on LightInTheBox</a>';
        $html .= '</div></article>';

        return $html;
    }

    /** @param array<string,mixed> $product */
    private function freshnessNote(array $product): string
    {
        $checked = strtotime((string) $product['captured_at']);
        $date = $checked === false ? (string) $product['captured_at'] : date('M j, Y', $checked);
        $market = trim((string) ($product['market'] ?? ''));
        $scope = $market === '' ? (string) $product['currency'] : $market.'/'.$product['currency'];

        return 'Price checked '.$date.' · '.$scope.'. Price, availability and options may change.';
    }

    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => '',
        };
    }

    private function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
