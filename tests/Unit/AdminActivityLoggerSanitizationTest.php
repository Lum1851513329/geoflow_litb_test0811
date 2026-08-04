<?php

namespace Tests\Unit;

use App\Support\AdminActivityLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AdminActivityLoggerSanitizationTest extends TestCase
{
    public function test_package_password_is_redacted_from_admin_activity_payload(): void
    {
        $method = new ReflectionMethod(AdminActivityLogger::class, 'sanitizePayload');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'package_password' => 'secret-123',
            'name' => '官网主站',
        ]);

        $this->assertSame('[redacted]', $payload['package_password']);
        $this->assertSame('官网主站', $payload['name']);
    }

    public function test_tokens_secrets_and_keys_are_redacted_recursively(): void
    {
        $method = new ReflectionMethod(AdminActivityLogger::class, 'sanitizePayload');
        $method->setAccessible(true);

        $payload = $method->invoke(null, [
            'preview_token' => 'encrypted-preview',
            'nested' => [
                'candidate_token' => 'encrypted-candidate',
                'provider_secret' => 'secret',
                'signing_key' => 'key',
                'monkey' => 'not-a-key-suffix',
            ],
            'library_id' => 42,
        ]);

        $this->assertSame('[redacted]', $payload['preview_token']);
        $this->assertSame('[redacted]', $payload['nested']['candidate_token']);
        $this->assertSame('[redacted]', $payload['nested']['provider_secret']);
        $this->assertSame('[redacted]', $payload['nested']['signing_key']);
        $this->assertSame('not-a-key-suffix', $payload['nested']['monkey']);
        $this->assertSame('42', $payload['library_id']);
    }
}
