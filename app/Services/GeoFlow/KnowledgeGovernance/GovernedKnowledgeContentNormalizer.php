<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use RuntimeException;

final class GovernedKnowledgeContentNormalizer
{
    public function normalize(string $raw): string
    {
        if (preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('governed_import.cleaned_document_utf8_invalid');
        }

        $normalized = \Normalizer::normalize($raw, \Normalizer::FORM_C);
        if ($normalized === false) {
            throw new RuntimeException('governed_import.cleaned_document_unicode_normalization_failed');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $normalized);
        $lines = [];
        $previousBlank = false;
        foreach (explode("\n", $text) as $sourceLine) {
            $line = $this->rstripUnicode($sourceLine);
            if (preg_match('/^\s{0,3}#{1,6}\s*$/u', $line) === 1 || preg_match('/^\s*\\\\\s*$/u', $line) === 1) {
                if ($lines !== [] && end($lines) !== '') {
                    $lines[] = '';
                    $previousBlank = true;
                }

                continue;
            }

            $line = $this->normalizeInlineLinkWrappers($line);
            $blank = $line === '';
            if ($blank && $previousBlank) {
                continue;
            }
            $lines[] = $line;
            $previousBlank = $blank;
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $markdown = implode("\n", $lines);

        return $markdown === '' ? '' : $markdown."\n";
    }

    public function hash(string $raw): string
    {
        return hash('sha256', $this->normalize($raw));
    }

    private function normalizeInlineLinkWrappers(string $line): string
    {
        if (! str_contains($line, '【')) {
            return $line;
        }

        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new RuntimeException('governed_import.cleaned_document_utf8_invalid');
        }

        $rendered = '';
        $position = 0;
        $length = count($characters);
        while (true) {
            $start = $this->findCharacter($characters, '【', $position);
            if ($start === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $closeLabel = $this->findCharacter($characters, '】', $start + 1);
            if ($closeLabel === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $opener = $closeLabel + 1;
            while ($opener < $length && $this->isUnicodeWhitespace($characters[$opener])) {
                $opener++;
            }
            if ($opener === $length || ! in_array($characters[$opener], ['(', '（'], true)) {
                $rendered .= implode('', array_slice($characters, $position, $closeLabel + 1 - $position));
                $position = $closeLabel + 1;

                continue;
            }

            $closeDestination = $this->findWrapperClose($characters, $opener);
            if ($closeDestination === null) {
                return $rendered.implode('', array_slice($characters, $position));
            }
            $destination = $this->stripUnicode(implode('', array_slice(
                $characters,
                $opener + 1,
                $closeDestination - $opener - 1,
            )));
            if ($this->isHttpUrl($destination) && ! $this->containsUnicodeWhitespace($destination)) {
                $label = $this->stripUnicode(implode('', array_slice(
                    $characters,
                    $start + 1,
                    $closeLabel - $start - 1,
                )));
                $rendered .= implode('', array_slice($characters, $position, $start - $position));
                $rendered .= '['.$label.']('.$destination.')';
            } else {
                $rendered .= implode('', array_slice(
                    $characters,
                    $position,
                    $closeDestination + 1 - $position,
                ));
            }
            $position = $closeDestination + 1;
        }
    }

    /** @param list<string> $characters */
    private function findCharacter(array $characters, string $needle, int $offset): ?int
    {
        for ($index = $offset, $length = count($characters); $index < $length; $index++) {
            if ($characters[$index] === $needle) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $characters */
    private function findWrapperClose(array $characters, int $opener): ?int
    {
        [$opening, $closing] = $characters[$opener] === '(' ? ['(', ')'] : ['（', '）'];
        $depth = 0;
        for ($index = $opener, $length = count($characters); $index < $length; $index++) {
            if ($characters[$index] === $opening) {
                $depth++;
            } elseif ($characters[$index] === $closing) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function rstripUnicode(string $value): string
    {
        return preg_replace('/[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u', '', $value) ?? $value;
    }

    private function stripUnicode(string $value): string
    {
        return preg_replace('/^[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+|[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u', '', $value) ?? $value;
    }

    private function isUnicodeWhitespace(string $character): bool
    {
        return preg_match('/^[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]$/u', $character) === 1;
    }

    private function containsUnicodeWhitespace(string $value): bool
    {
        return preg_match('/[\x{0009}-\x{000D}\x{001C}-\x{001F}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', $value) === 1;
    }

    private function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        $ipHost = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && $host !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && (! isset($parts['port']) || ((int) $parts['port'] >= 1 && (int) $parts['port'] <= 65535))
            && (
                filter_var($ipHost, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            );
    }
}
