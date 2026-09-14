<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\BaseLineEToCalculator;
use GardenIrrigationControl\Libs\SimpleHttpClient;
use PHPUnit\Framework\TestCase;

class BaseLineEToCalculatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Input validation tests – HTTP client must never be called
    // -------------------------------------------------------------------------

    public function testThrowsOnMissingLatitudeKey(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.location_coordinates_missing');

        $calculator->calculate3YearAverage(
            ['longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnMissingLongitudeKey(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.location_coordinates_missing');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnLatitudeOutOfRange(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.coordinates_out_of_range');

        $calculator->calculate3YearAverage(
            ['latitude' => 91.0, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnLongitudeOutOfRange(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.coordinates_out_of_range');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => -181.0],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnStartMonthBelowRange(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.invalid_month_selection');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            0,
            9,
            $httpClient
        );
    }

    public function testThrowsOnEndMonthAboveRange(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.baseline.invalid_month_selection');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            13,
            $httpClient
        );
    }

    // -------------------------------------------------------------------------
    // API response error handling
    // -------------------------------------------------------------------------

    public function testThrowsOnApiErrorResponse(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn(['error' => true, 'reason' => 'Invalid date range requested.']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.weather_api_failed');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnApiErrorResponseWithoutReason(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn(['error' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.weather_api_failed');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnMissingEtoFieldInResponse(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [mktime(0, 0, 0, 6, 15, 2024)]
                    // et0_fao_evapotranspiration key missing
                ]
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.invalid_api_structure');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnMissingTimeFieldInResponse(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'et0_fao_evapotranspiration' => [4.5]
                    // time key missing
                ]
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.invalid_api_structure');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsWhenNoTimestampsMatchSelectedSeason(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        // Response contains only January and February timestamps – season is May–September
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [
                        mktime(0, 0, 0, 1, 15, 2024), // January
                        mktime(0, 0, 0, 2, 15, 2024), // February
                    ],
                    'et0_fao_evapotranspiration' => [1.2, 1.4]
                ]
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.no_seasonal_measurements');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsWhenAllSeasonEtoValuesAreNull(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [
                        mktime(0, 0, 0, 6, 15, 2024),
                        mktime(0, 0, 0, 7, 15, 2024),
                    ],
                    'et0_fao_evapotranspiration' => [null, null]
                ]
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.no_seasonal_measurements');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    public function testThrowsOnUnbelievablyHighAverageETo(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time'                       => [mktime(0, 0, 0, 7, 1, 2024)],
                    'et0_fao_evapotranspiration' => [99.0]
                ]
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.baseline.unrealistic_result');

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );
    }

    // -------------------------------------------------------------------------
    // Happy path and calculation logic
    // -------------------------------------------------------------------------

    /**
     * Verifies correct average calculation and seasonal filtering for a standard (non-cross-year) season.
     * Timestamps in January are outside the May–September season and must be excluded.
     * Expected: (3.0 + 6.0 + 4.0) / 3 = 4.333... → rounded to 1 decimal = 4.3
     */
    public function testCalculatesCorrectAverageForStandardSeason(): void
    {
        $logger = new ArrayLogger();
        $calculator = new BaseLineEToCalculator($logger);
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [
                        mktime(0, 0, 0, 1, 15, 2024), // January – outside season, must be excluded
                        mktime(0, 0, 0, 5, 15, 2024), // May – in season
                        mktime(0, 0, 0, 7, 15, 2024), // July – in season
                        mktime(0, 0, 0, 9, 15, 2024), // September – in season
                    ],
                    'et0_fao_evapotranspiration' => [1.0, 3.0, 6.0, 4.0]
                ]
            ]);

        $result = $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );

        $this->assertSame(4.3, $result);
    }

    /**
     * Verifies that null ETo values within the season window are silently skipped.
     * Expected: (5.0 + 3.0) / 2 = 4.0
     */
    public function testNullEtoValuesWithinSeasonAreSkipped(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [
                        mktime(0, 0, 0, 6, 1, 2024),  // June – in season
                        mktime(0, 0, 0, 6, 15, 2024), // June – null, must be skipped
                        mktime(0, 0, 0, 7, 1, 2024),  // July – in season
                    ],
                    'et0_fao_evapotranspiration' => [5.0, null, 3.0]
                ]
            ]);

        $result = $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );

        $this->assertSame(4.0, $result);
    }

    /**
     * Verifies cross-year season filtering (November to March).
     * Timestamps in November and February must be included; June must be excluded.
     * Expected: (1.5 + 2.5) / 2 = 2.0
     */
    public function testCrossYearSeasonFiltersCorrectly(): void
    {
        $calculator = new BaseLineEToCalculator(new ArrayLogger());
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time' => [
                        mktime(0, 0, 0, 11, 15, 2024), // November – in season (>= startMonth 11)
                        mktime(0, 0, 0, 2, 15, 2025),  // February – in season (<= endMonth 3)
                        mktime(0, 0, 0, 6, 15, 2025),  // June – outside cross-year season
                    ],
                    'et0_fao_evapotranspiration' => [1.5, 2.5, 8.0]
                ]
            ]);

        $result = $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            11,
            3, // November to March
            $httpClient
        );

        $this->assertSame(2.0, $result);
    }

    /**
     * Verifies that the debug logger receives at least one message during a successful calculation.
     */
    public function testDebugLoggerIsCalledDuringCalculation(): void
    {
        $logger = new ArrayLogger();
        $calculator = new BaseLineEToCalculator($logger);
        $httpClient = $this->createMock(SimpleHttpClient::class);
        $httpClient->method('SendHTTPRequest')
            ->willReturn([
                'daily' => [
                    'time'                       => [mktime(0, 0, 0, 6, 15, 2024)],
                    'et0_fao_evapotranspiration' => [4.0]
                ]
            ]);

        $calculator->calculate3YearAverage(
            ['latitude' => 48.21, 'longitude' => 16.37],
            5,
            9,
            $httpClient
        );

        $this->assertNotEmpty($logger->getLogs(), 'Der Debug-Logger sollte mindestens einen Eintrag enthalten.');
    }
}
