<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

interface HttpClientInterface
{
    /**
     * Executes an HTTP GET request and returns the decoded JSON response.
     *
     * @param string $url
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    public function SendHTTPRequest(string $url, array $headers = []): array;
}
