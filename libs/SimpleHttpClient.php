<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Exception;

/**
 * A simple HTTP client for API requests
 */
class SimpleHttpClient implements HttpClientInterface
{
    // Global retry budget for each outbound HTTP request.
    // Increase this value to allow more attempts, decrease it to fail faster.
    private const HTTP_MAX_ATTEMPTS = 2;

    /**
     * Universal utility function for HTTP GET requests via cURL.
     *
     * @param string $url The full API URL
     * @param array<int, string> $headers Optional HTTP headers (e.g. for API keys)
     * @return array<string, mixed> the JSON-decoded server response
     * @throws Exception If cURL fails, the HTTP status code is not 200, or the URL is invalid
     */
    public function SendHTTPRequest(string $url, array $headers = []): array
    {
        $safeUrlForLogs = $this->sanitizeUrlForLogs($url);
        $this->validateRequestUrl($url);

        $defaultHeaders = [
            'Accept: application/json',
            'User-Agent: IP-Symcon/9.0 GardenIrrigationControl'
        ];

        // Add optional headers (e.g. for API keys or similar)
        $finalHeaders = array_merge($defaultHeaders, $headers);

        // Retry policy: perform up to HTTP_MAX_ATTEMPTS immediate tries for transient transport/upstream failures.
        // We intentionally do not sleep between attempts here to keep the caller flow simple and deterministic.
        for ($attempt = 1; $attempt <= self::HTTP_MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init();

            if ($ch === false) {
                throw new LocalizedException('error.http.curl_init_failed');
            }

            /**
             * Build the option array with explicit scalar and list types.
             * This keeps the HTTP transport deterministic and ensures the request uses the intended timeout and header
             * safeguards, which is important because irrigation logic depends on predictable upstream weather/API calls.
             */
            /** @var non-empty-string $url */
            /** @var non-empty-list<string> $finalHeaders */
            $options = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10, // Timeout after 10 seconds
                CURLOPT_CONNECTTIMEOUT => 3, // Connection timeout after 3 seconds
                CURLOPT_HTTPHEADER     => $finalHeaders,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_FAILONERROR    => false // Read the body even on errors (for API error messages)
            ];

            curl_setopt_array($ch, $options);

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            $response = curl_exec($ch);

            // 1. Catch cURL connection errors (e.g. DNS errors, timeout)
            if (!is_string($response)) {
                $errorCode = curl_errno($ch);
                $errorMsg = curl_error($ch);
                curl_close($ch);

                // Retry only for transient cURL failures (timeouts, temporary network/connectivity issues).
                if ($attempt < self::HTTP_MAX_ATTEMPTS && $this->shouldRetryOnCurlError($errorCode)) {
                    continue;
                }

                throw new LocalizedException('error.http.curl_request_failed', [$safeUrlForLogs, $errorCode, $errorMsg, $attempt, self::HTTP_MAX_ATTEMPTS]);
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 2. Check the HTTP status code
            if ($httpCode !== 200) {
                // Retry only for transient upstream responses (e.g. rate limit or temporary server errors).
                if ($attempt < self::HTTP_MAX_ATTEMPTS && $this->shouldRetryOnHttpStatus($httpCode)) {
                    continue;
                }

                throw new LocalizedException('error.http.http_request_failed', [$safeUrlForLogs, $httpCode, $attempt, self::HTTP_MAX_ATTEMPTS]);
            }

            // 3. Decode JSON
            try {
                $httpData = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new LocalizedException('error.http.invalid_json_response', [$safeUrlForLogs, $e->getMessage()]);
            }

            return $httpData;
        }

        throw new LocalizedException('error.http.unexpected_request_state');
    }

    /**
     * Marks transient transport failures that are safe to retry once.
     */
    protected function shouldRetryOnCurlError(int $errorCode): bool
    {
        $retryableErrors = [];

        if (defined('CURLE_OPERATION_TIMEDOUT')) {
            $retryableErrors[] = CURLE_OPERATION_TIMEDOUT;
        }
        if (defined('CURLE_COULDNT_RESOLVE_HOST')) {
            $retryableErrors[] = CURLE_COULDNT_RESOLVE_HOST;
        }
        if (defined('CURLE_COULDNT_CONNECT')) {
            $retryableErrors[] = CURLE_COULDNT_CONNECT;
        }
        if (defined('CURLE_SEND_ERROR')) {
            $retryableErrors[] = CURLE_SEND_ERROR;
        }
        if (defined('CURLE_RECV_ERROR')) {
            $retryableErrors[] = CURLE_RECV_ERROR;
        }
        if (defined('CURLE_GOT_NOTHING')) {
            $retryableErrors[] = CURLE_GOT_NOTHING;
        }
        if (defined('CURLE_SSL_CONNECT_ERROR')) {
            $retryableErrors[] = CURLE_SSL_CONNECT_ERROR;
        }

        return in_array($errorCode, $retryableErrors, true);
    }

    /**
     * Marks transient upstream responses where one immediate retry can recover.
     */
    protected function shouldRetryOnHttpStatus(int $httpCode): bool
    {
        return in_array($httpCode, [408, 425, 429, 500, 502, 503, 504], true);
    }

    /**
     * Validates URL, scheme and destination target against SSRF vectors.
     * HTTP is intentionally allowed for legacy APIs that do not support HTTPS.
     */
    protected function validateRequestUrl(string $url): void
    {
        if ($url === '') {
            throw new LocalizedInvalidArgumentException('error.http.url_empty');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new LocalizedInvalidArgumentException('error.http.url_invalid_format', [$url]);
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            throw new LocalizedInvalidArgumentException('error.http.url_parse_failed', [$url]);
        }

        $scheme = strtolower((string) ($parsedUrl['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new LocalizedInvalidArgumentException('error.http.url_scheme_unsupported', [$scheme]);
        }

        if (isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            throw new LocalizedInvalidArgumentException('error.http.url_user_info_forbidden');
        }

        $host = strtolower((string) ($parsedUrl['host'] ?? ''));
        if ($host === '') {
            throw new LocalizedInvalidArgumentException('error.http.url_host_missing');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new LocalizedInvalidArgumentException('error.http.localhost_forbidden', [$host]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                throw new LocalizedInvalidArgumentException('error.http.private_host_forbidden', [$host]);
            }
            return;
        }

        $resolvedIps = $this->resolveHostToIps($host);
        if (count($resolvedIps) === 0) {
            throw new LocalizedException('error.http.host_resolution_failed', [$host]);
        }

        foreach ($resolvedIps as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new LocalizedInvalidArgumentException('error.http.private_host_forbidden', [$host]);
            }
        }
    }

    /**
     * Resolves host to IPv4/IPv6 addresses.
     *
     * @return array<int, string>
     */
    protected function resolveHostToIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = dns_get_record($host, DNS_A + DNS_AAAA);
            if ($records !== false) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP)) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (count($ips) === 0 && function_exists('gethostbynamel')) {
            $ipv4List = gethostbynamel($host);
            if (is_array($ipv4List)) {
                foreach ($ipv4List as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Masks sensitive query parameter values while keeping URL context for debugging.
     */
    protected function sanitizeUrlForLogs(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $sanitized = $parts['scheme'] . '://';

        if (isset($parts['user'])) {
            $sanitized .= $parts['user'];
            if (isset($parts['pass'])) {
                $sanitized .= ':***';
            }
            $sanitized .= '@';
        }

        $sanitized .= $parts['host'];

        if (isset($parts['port'])) {
            $sanitized .= ':' . (int) $parts['port'];
        }

        $sanitized .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            if (count($queryParams) > 0) {
                $sensitiveKeys = [
                    'apikey',
                    'api_key',
                    'key',
                    'token',
                    'access_token',
                    'refresh_token',
                    'password',
                    'pass',
                    'secret'
                ];

                foreach ($queryParams as $key => $value) {
                    $normalizedKey = strtolower((string) $key);
                    if (in_array($normalizedKey, $sensitiveKeys, true)) {
                        $queryParams[$key] = '***';
                    }
                }

                $sanitized .= '?' . http_build_query($queryParams);
            } else {
                $sanitized .= '?' . $parts['query'];
            }
        }

        if (isset($parts['fragment'])) {
            $sanitized .= '#' . $parts['fragment'];
        }

        return $sanitized;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
