<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Laravel\Ai\Responses\Data\FinishReason;

use function Laravel\Ai\agent;

final class BriefQuestionPolishingService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function polish(AiModel $model, string $topic, string $question, string $language = 'en'): ?string
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $key = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($baseUrl === '' || $key === '') {
            return null;
        }
        $driver = OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) ($model->model_id ?? ''));
        $provider = OpenAiRuntimeProvider::registerProvider('brief_question', $driver, $baseUrl, $key);
        $prompt = "Rewrite the following editorial core question for GEO/SEO. Return only one question in {$language}. Keep the topic and search intent, make it specific and naturally answerable, and do not invent brand or product facts.\n\nTopic: {$topic}\nOriginal question: {$question}";
        try {
            $response = agent('You are an expert SEO/GEO editorial strategist.')->prompt($prompt, [], $provider, (string) ($model->model_id ?? ''));
            $text = trim((string) ($response->text ?? $response));
            $text = trim(preg_replace('/^```(?:text|json)?|```$/i', '', $text) ?? $text);
            return $text !== '' && mb_strlen($text) <= 500 ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
