<?php

namespace App\Domain\PromptComposition;

final class PromptModuleSnapshotIdentity
{
    public static function digest(
        string $content,
        mixed $variableContract,
        mixed $outputContract,
        mixed $parameterDefaults,
    ): string {
        return hash('sha256', self::canonicalJson([
            'content' => $content,
            'variable_contract' => $variableContract,
            'output_contract' => $outputContract,
            'parameter_defaults' => $parameterDefaults,
        ]));
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
