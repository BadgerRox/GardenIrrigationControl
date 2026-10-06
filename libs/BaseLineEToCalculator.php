<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Exception;
use InvalidArgumentException;

/**
 * Class BaseLineEToCalculator
 *
 * Handles historical climatological calculations via the Open-Meteo Archive API
 * to determine average baseline evapotranspiration (ETo) values over a 3-year span.
 */
class BaseLineEToCalculator
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Calculates the historical average baseline ETo for a specific location and seasonal period.
     *
     * @param array<string, mixed> $locationData Array containing 'latitude' and 'longitude' coordinates.
     * @param int $startMonth The starting month of the irrigation season (1-12).
     * @param int $endMonth The ending month of the irrigation season (1-12).
     * @param HttpClientInterface $httpClient HTTP client abstraction used for API requests.
     * @return float The calculated 3-year average baseline ETo in mm/day, rounded to 1 decimal place.
     * @throws InvalidArgumentException If location coordinates or selected months are invalid.
     * @throws Exception If the API response is corrupt, contains errors, or lacks data.
     */
    public function calculate3YearAverage(
        array $locationData,
        int $startMonth,
        int $endMonth,
        HttpClientInterface $httpClient
    ): float {

        // Validate location coordinates
        if (!isset($locationData['latitude'], $locationData['longitude'])) {
            throw new LocalizedInvalidArgumentException('error.baseline.location_coordinates_missing');
        }

        $lat = (float) $locationData['latitude'];
        $lon = (float) $locationData['longitude'];

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw new LocalizedInvalidArgumentException('error.baseline.coordinates_out_of_range');
        }

        // Validate seasonal month boundaries
        if ($startMonth < 1 || $startMonth > 12 || $endMonth < 1 || $endMonth > 12) {
            throw new LocalizedInvalidArgumentException('error.baseline.invalid_month_selection');
        }

        // Determine the target 3-year historical time window
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $lastFullYear = $currentYear - 1;
        $isCrossYear = ($startMonth > $endMonth);

        if (!$isCrossYear) {
            // Standard year pattern (e.g., May to September)
            if ($currentMonth > $endMonth) {
                $lastFullYear = $currentYear;
            }
        } else {
            // Cross-year pattern (e.g., November to March)
            if ($currentMonth > $endMonth) {
                $lastFullYear = $currentYear - 1;
            } else {
                $lastFullYear = $currentYear - 2;
            }
        }

        $startYear = $lastFullYear - 2;
        $endYear = $lastFullYear;

        // Force full calendar years to prevent micro-shifting of the data baseline
        $startDate = $startYear . '-01-01';
        $endDate = $endYear . '-12-31';

        // Assemble the Open-Meteo Archive API endpoint URL
        $url = sprintf(
            'https://archive-api.open-meteo.com/v1/archive?latitude=%F&longitude=%F&start_date=%s&end_date=%s&daily=et0_fao_evapotranspiration&timeformat=unixtime&timezone=auto',
            $lat,
            $lon,
            $startDate,
            $endDate
        );

        $this->logger?->debug('calculate3YearAverage', 'Starting API request for URL: ' . $url);

        // Execute HTTP Request via the provided client
        $data = $httpClient->SendHTTPRequest($url);

        if (isset($data['error']) && $data['error'] === true) {
            $apiError = $data['reason'] ?? 'Unknown API error response.';
            throw new LocalizedException('error.baseline.weather_api_failed', [$apiError]);
        }

        if (!isset($data['daily']['et0_fao_evapotranspiration'], $data['daily']['time']) ||
            !is_array($data['daily']['et0_fao_evapotranspiration']) ||
            !is_array($data['daily']['time'])
        ) {
            throw new LocalizedException('error.baseline.invalid_api_structure');
        }

        // Filter historical days matching the specific seasonal configuration
        $etoValues = [];
        foreach ($data['daily']['time'] as $index => $timestamp) {
            $etoValue = $data['daily']['et0_fao_evapotranspiration'][$index];

            if ($etoValue === null) {
                continue;
            }

            $month = (int) date('n', (int) $timestamp);
            $isWithinSeason = false;

            if (!$isCrossYear) {
                if ($month >= $startMonth && $month <= $endMonth) {
                    $isWithinSeason = true;
                }
            } else {
                if ($month >= $startMonth || $month <= $endMonth) {
                    $isWithinSeason = true;
                }
            }

            if ($isWithinSeason) {
                $etoValues[] = (float) $etoValue;
            }
        }

        if (count($etoValues) === 0) {
            throw new LocalizedException('error.baseline.no_seasonal_measurements');
        }

        // Calculate the mathematical arithmetic mean
        $averageETo = round(array_sum($etoValues) / count($etoValues), 1);

        // System boundaries sanity check
        if ($averageETo < 0.1 || $averageETo > 50.0) {
            throw new LocalizedException('error.baseline.unrealistic_result', [$averageETo]);
        }

        return $averageETo;
    }
}
