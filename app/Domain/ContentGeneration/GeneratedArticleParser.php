<?php

namespace App\Domain\ContentGeneration;

final class GeneratedArticleParser
{
    /**
     * @return array{title: ?string, content: string}
     */
    public function extractTitle(string $markdown): array
    {
        // Split into lines, preserving line endings
        $lines = preg_split('/(\r?\n)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Skip leading blank lines to find the H1
        $h1LineIndex = null;
        $titleMatch = null;
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i += 2) { // Step by 2 because split includes delimiters
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            // Check if this line is an H1
            if (preg_match('/^\s*#(?!#)\s*(.*?)\s*#*\s*$/u', $line, $matches)) {
                $h1LineIndex = $i;
                $titleMatch = $matches[1];
            }
            break;
        }

        // If no H1 found, return null title with unchanged content
        if ($h1LineIndex === null) {
            return ['title' => null, 'content' => $markdown];
        }

        // Extract and trim the title
        $title = trim($titleMatch ?? '');
        if ($title === '') {
            return ['title' => null, 'content' => $markdown];
        }

        // Reconstruct content: skip all elements up to and including the H1 line and its newline
        $contentParts = array_slice($lines, $h1LineIndex + 2); // +2 to skip line and its newline
        $content = trim(implode('', $contentParts));

        return ['title' => $title, 'content' => $content];
    }
}
