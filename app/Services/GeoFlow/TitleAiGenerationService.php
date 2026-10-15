<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

use function Laravel\Ai\agent;

/**
 * 标题 AI 生成服务。
 *
 * 该服务负责：
 * 1. 基于 ai_models 配置发起真实模型调用；
 * 2. 在模型不可用时使用模板兜底，保证流程可用性；
 * 3. 输出统一结构，便于控制器处理入库逻辑。
 */
class TitleAiGenerationService
{
    /**
     * 复用统一 API Key 解密组件，避免标题生成链路与其他 AI 链路出现差异。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiUsageQuotaService $usageQuota,
    ) {}

    /**
     * 生成标题列表。
     *
     * @param  list<string>  $keywords
     * @return array{
     *   titles:list<string>,
     *   fallback_used:bool,
     *   fallback_reason:?string
     * }
     */
    public function generateTitles(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt = '',
        string $outputLanguage = 'en',
    ): array {
        $reservation = null;

        try {
            $reservation = $this->usageQuota->reserveModel($aiModel);
            if ($reservation === null) {
                throw new \RuntimeException('ai_daily_limit_reached');
            }

            $content = $this->requestTitlesFromModel($aiModel, $keywords, $count, $style, $customPrompt, $outputLanguage);
            $items = array_slice($this->parseGeneratedItems($content, $keywords, $outputLanguage), 0, $count);
            if ($items !== []) {
                try {
                    $this->usageQuota->recordModelSuccess($reservation);
                } catch (Throwable $exception) {
                    report($exception);
                }

                return [
                    'titles' => array_column($items, 'title'),
                    'items' => $items,
                    'fallback_used' => false,
                    'fallback_reason' => null,
                ];
            }
        } catch (Throwable $exception) {
            if ($reservation instanceof AiUsageReservation) {
                $this->usageQuota->releaseModel($reservation);
            }

            return [
                'titles' => array_column($items = $this->generateMockTitleItems($keywords, $count, $style), 'title'),
                'items' => $items,
                'fallback_used' => true,
                'fallback_reason' => $exception->getMessage(),
            ];
        }

        if ($reservation instanceof AiUsageReservation) {
            $this->usageQuota->releaseModel($reservation);
        }

        return [
            'titles' => array_column($items = $this->generateMockTitleItems($keywords, $count, $style), 'title'),
            'items' => $items,
            'fallback_used' => true,
            'fallback_reason' => 'empty_result',
        ];
    }

    /**
     * 请求真实模型生成标题。
     *
     * @param  list<string>  $keywords
     */
    private function requestTitlesFromModel(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt,
        string $outputLanguage,
    ): string {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('ai_url_missing');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ai_key_missing');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('title_ai', $driver, $providerUrl, $apiKey);

        $styleMap = [
            'professional' => 'professional and authoritative',
            'attractive' => 'engaging without clickbait',
            'seo' => 'search-optimized',
            'creative' => 'creative and specific',
            'question' => 'question-led',
        ];
        $styleDescription = $styleMap[$style] ?? 'professional and authoritative';
        $languageNames = ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese'];
        $languageName = $languageNames[$outputLanguage] ?? 'English';
        $keywordsText = implode("\n", array_map(static fn (string $keyword): string => '- '.$keyword, $keywords));

        $systemPrompt = "You are an expert SEO and GEO editorial title strategist. Write only in {$languageName}.";
        $userPrompt = "Generate {$count} {$styleDescription} article titles from the approved keyword scope below.\n\nKeywords:\n{$keywordsText}\n\n";
        if ($customPrompt !== '') {
            $userPrompt .= "Additional requirements: {$customPrompt}\n\n";
        }
        $userPrompt .= "Requirements:\n1. Write every title in {$languageName}.\n2. Each title must focus on exactly one listed keyword.\n3. Never combine unrelated products or topics.\n4. Return strict JSON only in this shape: {\"titles\":[{\"title\":\"...\",\"keyword\":\"exact listed keyword\"}]}.\n5. The keyword value must exactly match one item from the approved list.\n6. Do not add markdown, commentary, or headings.";

        try {
            $response = agent($systemPrompt)->prompt(
                $userPrompt,
                [],
                $providerName,
                (string) ($aiModel->model_id ?? '')
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);

        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new \RuntimeException('ai_empty_stream_content');
            }

            throw new \RuntimeException('ai_empty_content');
        }

        return $content;
    }

    /**
     * 解析模型输出文本为标题列表。
     *
     * @return list<string>
     */
    private function parseGeneratedTitles(string $content): array
    {
        $titles = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $title = preg_replace('/^\d+[\.\)\-、\s]*/u', '', trim($line));
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $titles[] = $title;
        }

        return array_values(array_unique($titles));
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{title:string,keyword:string}>
     */
    private function parseGeneratedItems(string $content, array $keywords, string $outputLanguage): array
    {
        $normalized = trim($content);
        $normalized = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $normalized) ?? $normalized;
        $payload = json_decode($normalized, true);
        $items = [];
        if (is_array($payload) && is_array($payload['titles'] ?? null)) {
            foreach ($payload['titles'] as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $keyword = trim((string) ($item['keyword'] ?? ''));
                if ($title === '' || ! in_array($keyword, $keywords, true) || ! $this->isLanguageCompatible($title, $outputLanguage)) {
                    continue;
                }
                $items[$title] = ['title' => $title, 'keyword' => $keyword];
            }

            return array_values($items);
        }

        if (count($keywords) === 1) {
            foreach ($this->parseGeneratedTitles($content) as $title) {
                if ($this->isLanguageCompatible($title, $outputLanguage)) {
                    $items[$title] = ['title' => $title, 'keyword' => $keywords[0]];
                }
            }
        }

        return array_values($items);
    }

    private function isLanguageCompatible(string $title, string $outputLanguage): bool
    {
        return $outputLanguage !== 'en' || preg_match('/\p{Han}/u', $title) !== 1;
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @return list<string>
     */
    private function generateMockTitleItems(array $keywords, int $count, string $style): array
    {
        $styleTemplates = [
            'professional' => [
                '{keyword}: An In-Depth Guide',
                'What to Know About {keyword}',
                'A Practical Guide to {keyword}',
            ],
            'attractive' => [
                '{keyword}: Key Details Worth Knowing',
                'How to Get More from {keyword}',
                '{keyword}: A Smarter Way to Choose',
            ],
            'seo' => [
                '{keyword}: The Complete Guide',
                '{keyword}: Frequently Asked Questions',
                'How to Choose the Right {keyword}',
            ],
            'creative' => [
                'A Fresh Look at {keyword}',
                '{keyword}, Reconsidered',
                'New Ways to Think About {keyword}',
            ],
            'question' => [
                'Is {keyword} Right for You?',
                'What Makes {keyword} Worth Considering?',
                'How Should You Choose {keyword}?',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];
        $titles = [];
        for ($index = 0; $index < $count; $index++) {
            $keyword = $keywords[array_rand($keywords)];
            $template = $templates[array_rand($templates)];
            $titles[] = ['title' => str_replace('{keyword}', $keyword, $template), 'keyword' => $keyword];
        }

        return $titles;
    }
}
