<?php

namespace App\Domain\ContentGeneration;

final class GeneratedArticleParser
{
    /**
     * @return array{title: ?string, content: string}
     */
    public function extractTitle(string $markdown): array
    {
        // Extract the first line
        $lines = preg_split('/\r?\n/', $markdown, 2);
        $firstLine = $lines[0] ?? '';
        $rest = $lines[1] ?? '';

        // Check if the first line is an H1
        if (!preg_match('/^\s*#(?!#)\s*(.*?)\s*#*\s*$/u', $firstLine, $matches)) {
            return ['title' => null, 'content' => $markdown];
        }

        // Extract and trim the title
        $title = trim($matches[1]);
        if ($title === '') {
            return ['title' => null, 'content' => $markdown];
        }

        // Reconstruct the content without the H1 line
        $content = trim($rest);

        return ['title' => $title, 'content' => $content];
    }
}
