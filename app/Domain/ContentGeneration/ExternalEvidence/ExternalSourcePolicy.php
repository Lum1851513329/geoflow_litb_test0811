<?php

namespace App\Domain\ContentGeneration\ExternalEvidence;

final class ExternalSourcePolicy
{
    public function accepts(string $url, array $domains): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || $this->unsafeIpLiteral($host) || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        $allowed = array_values(array_filter(array_map(static fn ($domain): string => strtolower(trim((string) $domain, " .\t\n\r\0\x0B")), $domains)));
        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function unsafeIpLiteral(string $host): bool
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return preg_match('/^(?:0x[0-9a-f]+|[0-9.]+)$/i', $host) === 1;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
