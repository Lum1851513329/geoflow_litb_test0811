<?php

namespace App\Domain\ContentGeneration;

use App\Models\ContentGenerationRun;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class SensitiveContentRedactor
{
    /** @return list<string> */
    public function valuesForRun(ContentGenerationRun $run): array
    {
        if ($run->encrypted_model_payload === null) {
            return [];
        }
        $payload = json_decode(Crypt::decryptString((string) $run->encrypted_model_payload), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('The encrypted model payload is invalid.');
        }

        $unique = [];
        foreach ((array) ($payload['sensitive_values'] ?? []) as $value) {
            $this->collect($value, $unique);
        }
        $values = array_values($unique);
        usort($values, static fn (string $left, string $right): int => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));

        return $values;
    }

    /** @param list<string> $sensitiveValues */
    public function string(string $value, array $sensitiveValues): string
    {
        $value = str_replace($sensitiveValues, '[REDACTED]', $value);
        $value = preg_replace('/(authorization\s*:\s*bearer\s+)[^\s,;]+/iu', '$1[REDACTED]', $value) ?? $value;
        $value = preg_replace('/((?:api[_-]?key|access[_-]?token|secret)\s*[=:]\s*)[^\s,;]+/iu', '$1[REDACTED]', $value) ?? $value;

        return preg_replace('/\bsk-[A-Za-z0-9_-]+\b/u', '[REDACTED]', $value) ?? $value;
    }

    /** @param list<string> $sensitiveValues */
    public function value(mixed $value, array $sensitiveValues): mixed
    {
        if (is_string($value)) {
            return $this->string($value, $sensitiveValues);
        }
        if (! is_array($value)) {
            return $value;
        }

        $safe = [];
        foreach ($value as $key => $item) {
            $safeKey = is_string($key) ? $this->string($key, $sensitiveValues) : $key;
            $safe[$safeKey] = $this->value($item, $sensitiveValues);
        }

        return $safe;
    }

    /** @param array<string, string> $values */
    private function collect(mixed $value, array &$values): void
    {
        if (is_array($value)) {
            if ($value !== []) {
                $rendered = $this->canonicalJson($value);
                $values['value:'.$rendered] = $rendered;
            }
            foreach ($value as $item) {
                $this->collect($item, $values);
            }

            return;
        }
        if (! is_scalar($value)) {
            return;
        }

        $rendered = is_string($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($rendered !== '') {
            $values['value:'.$rendered] = $rendered;
        }
    }

    private function canonicalJson(array $value): string
    {
        if (array_is_list($value)) {
            $canonical = array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item, $value);
        } else {
            $canonical = $this->canonicalize($value);
        }

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
