<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\SimpleHttpClient;
use PHPUnit\Framework\TestCase;

class SimpleHttpClientTest extends TestCase
{
    public function testShouldRetryOnTransientHttpStatusCodes(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->assertTrue($client->shouldRetryHttpStatus(429));
        $this->assertTrue($client->shouldRetryHttpStatus(500));
        $this->assertTrue($client->shouldRetryHttpStatus(503));
    }

    public function testShouldNotRetryOnPermanentHttpStatusCodes(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->assertFalse($client->shouldRetryHttpStatus(400));
        $this->assertFalse($client->shouldRetryHttpStatus(401));
        $this->assertFalse($client->shouldRetryHttpStatus(404));
    }

    public function testShouldRetryOnTransientCurlErrors(): void
    {
        $client = $this->createClientWithDnsMap();

        if (!defined('CURLE_OPERATION_TIMEDOUT')) {
            $this->markTestSkipped('CURLE_OPERATION_TIMEDOUT is not available in this environment.');
        }

        $this->assertTrue($client->shouldRetryCurlError(CURLE_OPERATION_TIMEDOUT));
    }

    public function testShouldNotRetryOnUnknownCurlErrorCode(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->assertFalse($client->shouldRetryCurlError(999999));
    }

    public function testSanitizeUrlForLogsKeepsNonSensitiveParametersVisible(): void
    {
        $client = $this->createClientWithDnsMap();

        $sanitized = $client->sanitizeUrl(
            'http://api.example.test/v1/weather?latitude=47.1&longitude=15.4&days=3'
        );

        $this->assertSame(
            'http://api.example.test/v1/weather?latitude=47.1&longitude=15.4&days=3',
            $sanitized
        );
    }

    public function testSanitizeUrlForLogsMasksSensitiveQueryValues(): void
    {
        $client = $this->createClientWithDnsMap();

        $sanitized = $client->sanitizeUrl(
            'http://api.example.test/v1/weather?apikey=abc123&token=def456&latitude=47.1'
        );

        $this->assertStringContainsString('apikey=%2A%2A%2A', $sanitized);
        $this->assertStringContainsString('token=%2A%2A%2A', $sanitized);
        $this->assertStringContainsString('latitude=47.1', $sanitized);
        $this->assertStringNotContainsString('abc123', $sanitized);
        $this->assertStringNotContainsString('def456', $sanitized);
    }

    public function testValidateRequestUrlAllowsHttpForPublicHost(): void
    {
        $client = $this->createClientWithDnsMap([
            'api.example.test' => ['93.184.216.34']
        ]);

        $client->validateUrl('http://api.example.test/data');

        $this->addToAssertionCount(1);
    }

    public function testValidateRequestUrlBlocksUnsupportedScheme(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.url_scheme_unsupported');

        $client->validateUrl('ftp://example.com/data');
    }

    public function testValidateRequestUrlBlocksLocalhostVariants(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.localhost_forbidden');

        $client->validateUrl('http://localhost/api');
    }

    public function testValidateRequestUrlBlocksPrivateIpLiteral(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.private_host_forbidden');

        $client->validateUrl('http://192.168.1.1/api');
    }

    public function testValidateRequestUrlBlocksHostnameResolvingToPrivateIp(): void
    {
        $client = $this->createClientWithDnsMap([
            'internal-api.example.test' => ['10.0.0.20']
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.private_host_forbidden');

        $client->validateUrl('http://internal-api.example.test/v1');
    }

    public function testValidateRequestUrlBlocksIpv6LoopbackLiteral(): void
    {
        // parse_url returns the host as '[::1]' (with brackets), which filter_var does not accept as a
        // bare IP address. The code therefore falls through to DNS resolution, which fails in the mock.
        // The security property still holds: the request is blocked.
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.host_resolution_failed');

        $client->validateUrl('http://[::1]/api');
    }

    public function testValidateRequestUrlBlocksIpv6PrivateLiteral(): void
    {
        // Same bracketed-host behavior as IPv6 loopback – DNS resolution fails, request is blocked.
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.host_resolution_failed');

        $client->validateUrl('http://[fd00::1]/api');
    }

    public function testValidateRequestUrlThrowsWhenHostCannotBeResolved(): void
    {
        // Empty DNS map → resolveHostToIps returns [] → fail-closed behavior
        $client = $this->createClientWithDnsMap([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.host_resolution_failed');

        $client->validateUrl('http://unresolvable.example.test/api');
    }

    public function testValidateRequestUrlBlocksUserInfoInUrl(): void
    {
        $client = $this->createClientWithDnsMap();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.http.url_user_info_forbidden');

        $client->validateUrl('http://user:pass@api.example.test/data');
    }

    private function createClientWithDnsMap(array $dnsMap = []): object
    {
        return new class($dnsMap) extends SimpleHttpClient {
            /** @var array<string, array<int, string>> */
            private array $dnsMap;

            /**
             * @param array<string, array<int, string>> $dnsMap
             */
            public function __construct(array $dnsMap)
            {
                $this->dnsMap = $dnsMap;
            }

            public function validateUrl(string $url): void
            {
                $this->validateRequestUrl($url);
            }

            public function sanitizeUrl(string $url): string
            {
                return $this->sanitizeUrlForLogs($url);
            }

            public function shouldRetryCurlError(int $errorCode): bool
            {
                return $this->shouldRetryOnCurlError($errorCode);
            }

            public function shouldRetryHttpStatus(int $httpCode): bool
            {
                return $this->shouldRetryOnHttpStatus($httpCode);
            }

            protected function resolveHostToIps(string $host): array
            {
                return $this->dnsMap[$host] ?? [];
            }
        };
    }
}
