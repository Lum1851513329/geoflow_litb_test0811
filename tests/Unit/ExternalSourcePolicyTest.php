<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\ExternalEvidence\ExternalSourcePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalSourcePolicyTest extends TestCase
{
    #[DataProvider('urls')]
    public function test_only_accepts_safe_http_sources_in_an_allowed_domain(string $url, array $domains, bool $expected): void
    {
        self::assertSame($expected, (new ExternalSourcePolicy)->accepts($url, $domains));
    }

    public static function urls(): array
    {
        return [
            ['https://lightinthebox.com/item', ['lightinthebox.com'], true],
            ['https://www.lightinthebox.com/item', ['lightinthebox.com'], true],
            ['https://lightinthebox.com.evil.test/item', ['lightinthebox.com'], false],
            ['https://evil-lightinthebox.com/item', ['lightinthebox.com'], false],
            ['http://127.0.0.1/admin', [], false],
            ['http://[::1]/admin', [], false],
            ['http://169.254.169.254/latest', [], false],
            ['file:///etc/passwd', [], false],
            ['https://user:pass@example.com/a', [], false],
            ['https://example.com/a', [], true],
        ];
    }
}
