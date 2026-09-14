<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use InvalidArgumentException;
use RuntimeException;

class RainForecastCalculator
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Fetches the expected precipitation sum and the maximum precipitation probability
     * for the configured forecast window from Open-Meteo.
     *
     * @param array<string, mixed> $locationData
     * @return array{rainSum: float, maxProb: float}
     * @throws InvalidArgumentException If the location, timezone or forecast horizon is invalid.
     * @throws RuntimeException If the API response is missing, corrupt or contains invalid values.
     */
    public function getRainForecast(
        array $locationData,
        string $timezone,
        int $forecastHours = 12,
        ?HttpClientInterface $httpClient = null
    ): array {
        $latitude = $locationData['latitude'] ?? null;
        $longitude = $locationData['longitude'] ?? null;

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new LocalizedInvalidArgumentException('error.rain_forecast.location_invalid');
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            throw new LocalizedInvalidArgumentException('error.rain_forecast.coordinates_out_of_range');
        }

        if (trim($timezone) === '') {
            throw new LocalizedInvalidArgumentException('error.rain_forecast.timezone_missing');
        }

        if ($forecastHours < 1 || $forecastHours > 168) {
            throw new LocalizedInvalidArgumentException('error.rain_forecast.forecast_hours_invalid', [$forecastHours]);
        }

        if ($httpClient === null) {
            $httpClient = new SimpleHttpClient();
        }

        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%F&longitude=%F&hourly=precipitation,precipitation_probability&models=ecmwf_ifs&timezone=%s&forecast_hours=%d',
            $latitude,
            $longitude,
            urlencode($timezone),
            $forecastHours
        );

        $this->logger?->debug('GetRainForecast', 'Starting forecast request for URL: ' . $url);

        $apiData = $httpClient->SendHTTPRequest($url);

        if (isset($apiData['error']) && $apiData['error'] === true) {
            throw new LocalizedRuntimeException('error.rain_forecast.api_failed', [$apiData['reason'] ?? 'Unknown API error']);
        }

        if (
            !isset($apiData['hourly']['precipitation'], $apiData['hourly']['precipitation_probability']) ||
            !is_array($apiData['hourly']['precipitation']) ||
            !is_array($apiData['hourly']['precipitation_probability'])
        ) {
            throw new LocalizedRuntimeException('error.rain_forecast.invalid_api_structure');
        }

        $precipitationValues = $apiData['hourly']['precipitation'];
        $probabilityValues = $apiData['hourly']['precipitation_probability'];

        if (count($precipitationValues) === 0 || count($probabilityValues) === 0) {
            throw new LocalizedRuntimeException('error.rain_forecast.no_data');
        }

        if (count($precipitationValues) !== count($probabilityValues)) {
            throw new LocalizedRuntimeException('error.rain_forecast.array_length_mismatch');
        }

        foreach ($precipitationValues as $index => $precipitation) {
            $probability = $probabilityValues[$index] ?? null;

            if (!is_numeric($precipitation) || !is_numeric($probability)) {
                throw new LocalizedRuntimeException('error.rain_forecast.non_numeric_values');
            }

            $precipitationValue = (float) $precipitation;
            $probabilityValue = (float) $probability;

            if ($precipitationValue < 0.0 || $probabilityValue < 0.0) {
                throw new LocalizedRuntimeException('error.rain_forecast.negative_values');
            }
        }

        $rainSum = round(max(0.0, array_sum(array_map(static fn (mixed $value): float => (float) $value, $precipitationValues))), 4);
        $maxProb = round(max(0.0, max(array_map(static fn (mixed $value): float => (float) $value, $probabilityValues))), 4);

        $this->logger?->debug('GetRainForecast', sprintf('Forecast ausgewertet: rainSum=%.4f mm, maxProb=%.4f %%', $rainSum, $maxProb));

        return [
            'rainSum' => $rainSum,
            'maxProb' => $maxProb
        ];
    }
}
