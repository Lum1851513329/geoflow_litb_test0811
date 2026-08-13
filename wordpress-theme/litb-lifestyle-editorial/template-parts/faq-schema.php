<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @return list<array{question: string, answer: string}>
 */
function litb_extract_faq_pairs( string $html ): array {
    if ( trim( $html ) === '' ) {
        return [];
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
    libxml_clear_errors();

    $xpath = new DOMXPath( $doc );
    $headings = $xpath->query( '//h2 | //h3' );
    if ( $headings === false || $headings->length === 0 ) {
        return [];
    }

    $in_faq_section = false;
    $pairs = [];
    $current_question = null;
    $current_answer_parts = [];

    $flush = static function () use ( &$pairs, &$current_question, &$current_answer_parts ): void {
        if ( $current_question !== null && $current_answer_parts !== [] ) {
            $pairs[] = [
                'question' => $current_question,
                'answer' => trim( implode( ' ', $current_answer_parts ) ),
            ];
        }
        $current_question = null;
        $current_answer_parts = [];
    };

    foreach ( $headings as $heading ) {
        $tag = strtolower( $heading->nodeName );
        $text = trim( $heading->textContent );

        if ( $tag === 'h2' ) {
            $flush();
            $in_faq_section = ( stripos( $text, 'frequently asked questions' ) !== false );
            continue;
        }

        // $tag === 'h3'
        if ( ! $in_faq_section ) {
            continue;
        }
        $flush();
        $current_question = $text;

        for ( $node = $heading->nextSibling; $node !== null; $node = $node->nextSibling ) {
            if ( $node->nodeType === XML_ELEMENT_NODE && in_array( strtolower( $node->nodeName ), [ 'h2', 'h3' ], true ) ) {
                break;
            }
            if ( $node->nodeType === XML_ELEMENT_NODE && strtolower( $node->nodeName ) === 'p' ) {
                $current_answer_parts[] = trim( $node->textContent );
            }
        }
    }
    $flush();

    return $pairs;
}
