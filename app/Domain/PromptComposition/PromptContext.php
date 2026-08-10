<?php

namespace App\Domain\PromptComposition;

use InvalidArgumentException;

final readonly class PromptContext
{
    /**
     * @param array<string, mixed> $strategy
     * @param array<string, mixed> $blueprint
     * @param list<mixed> $keywords
     * @param list<mixed> $evidence
     * @param list<mixed> $products
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public array $strategy,
        public array $blueprint,
        public array $keywords,
        public array $evidence,
        public array $products,
        public string $language,
        public string $market,
        public array $variables = [],
        public int $modelTokenBudget = 16_000,
    ) {
        if ($modelTokenBudget < 1) {
            throw new InvalidArgumentException('The model token budget must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function variableValues(): array
    {
        return array_merge(
            $this->blueprint,
            $this->strategy,
            $this->variables,
            [
                'strategy' => $this->strategy,
                'blueprint' => $this->blueprint,
                'keywords' => $this->keywords,
                'evidence' => $this->evidence,
                'products' => $this->products,
                'language' => $this->language,
                'market' => $this->market,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'strategy' => $this->strategy,
            'blueprint' => $this->blueprint,
            'keywords' => $this->keywords,
            'evidence' => $this->evidence,
            'products' => $this->products,
            'language' => $this->language,
            'market' => $this->market,
            'variables' => $this->variables,
            'model_token_budget' => $this->modelTokenBudget,
        ];
    }
}
