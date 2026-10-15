<?php

namespace App\Support\Site;

final class ArticleFaqSchema
{
    /** @return list<array{question:string,answer:string}> */
    public static function extract(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $inFaqSection = false;
        $pairs = [];
        $currentQuestion = null;
        $currentAnswerLines = [];

        $flush = function () use (&$pairs, &$currentQuestion, &$currentAnswerLines): void {
            if ($currentQuestion !== null) {
                $answer = trim(implode(' ', $currentAnswerLines));
                if ($answer !== '') {
                    $pairs[] = ['question' => $currentQuestion, 'answer' => $answer];
                }
            }
            $currentQuestion = null;
            $currentAnswerLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', trim($line), $matches) === 1) {
                if ($inFaqSection) {
                    $flush();
                    break;
                }
                if (stripos(trim($matches[1]), 'frequently asked questions') !== false) {
                    $inFaqSection = true;
                }

                continue;
            }
            if (! $inFaqSection) {
                continue;
            }
            if (preg_match('/^###\s+(.+)$/', trim($line), $matches) === 1) {
                $flush();
                $currentQuestion = trim($matches[1]);

                continue;
            }
            if (trim($line) !== '') {
                $currentAnswerLines[] = trim($line);
            }
        }
        $flush();

        return $pairs;
    }
}
