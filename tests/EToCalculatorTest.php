<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\EToCalculator;
use GardenIrrigationControl\Libs\SimpleHttpClient;
use PHPUnit\Framework\TestCase;

class EToCalculatorTest extends TestCase
{
    public function testCalculateDailyEToThrowsOnMissingArchivePayloadKeys(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());

        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn([
                'elevation' => 74.0,
                'daily'     => [
                    'shortwave_radiation_sum' => [25.0]
                ]
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.archive_data_missing_fields');

        $calculator->calculateDailyETo(
            50.836983,
            4.374601,
            '2026-06-30',
            181,
            'Europe/Brussels',
            2.0,
            [
                'temp_min'     => 15.0,
                'temp_max'     => 28.0,
                'humidity_min' => 45.0,
                'humidity_max' => 85.0,
                'baro_avg'     => 1000.0
            ],
            $httpClientMock
        );
    }

    /**
     * Tests the daily ETo calculation using a predefined valid weather data payload
     * and a mocked HTTP client response for solar radiation.
     */
    public function testCalculateDailyEToWithValidPayload(): void
    {
        // 1. Initialize the debug logger and the system under test (SUT)
        $logger = new ArrayLogger();
        $calculator = new EToCalculator($logger);

        // 2. Prepare fixed geographical and temporal test parameters
        $lat = 50.836983;
        $lon = 4.374601;
        $dateKey = '2026-06-30';
        $dayOfYear = 181;
        $timezone = 'Europe/Brussels';
        $windHeight = 2.0;

        // Simulated aggregated weather data from the IP-Symcon archive
        $archivePayload = [
            'temp_min'     => 15.0,
            'temp_max'     => 28.0,
            'humidity_min' => 45.0,
            'humidity_max' => 85.0,
            'baro_avg'     => 1000.00,
            'wind_avg'     => 2.5
        ];

        // Intercept internal calculator debug logs with ArrayLogger for assertions
        $logger = new ArrayLogger();

        // 3. Mock the HTTP client to bypass external API calls and provide a predictable JSON response
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn([
                'elevation' => 74.0,
                'daily'     => [
                    'shortwave_radiation_sum' => [25.0]
                ]
            ]);

        // 4. Execute the calculation using the injected mock client
        $result = $calculator->calculateDailyETo(
            $lat,
            $lon,
            $dateKey,
            $dayOfYear,
            $timezone,
            $windHeight,
            $archivePayload,
            $httpClientMock
        );

        // Print intercepted calculation logs directly to the test output
        echo "\n\n--- INTERNAL CALCULATOR METRICS ---\n";
        foreach ($logger->getLogs() as $logEntry) {
            echo sprintf("%-25s: %s\n", $logEntry['context'], $logEntry['message']);
        }
        echo "------------------------------------\n\n";

        // 5. Assert that the result matches the verified mathematical reference value within the delta tolerance
        $expectedETo = 4.70899504667607;
        $this->assertEqualsWithDelta(
            $expectedETo,
            $result,
            0.0001,
            'The calculated ETo deviates from the expected FAO-56 reference value!'
        );
    }

    public function testCalculateDailyEToThrowsWhenApiReturnsErrorResponse(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn(['error' => true, 'reason' => 'Too many requests']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.weather_api_failed');

        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $this->validArchivePayload(), $httpClientMock);
    }

    public function testCalculateDailyEToThrowsWhenApiResponseMissingElevation(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn(['daily' => ['shortwave_radiation_sum' => [25.0]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.invalid_api_structure');

        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $this->validArchivePayload(), $httpClientMock);
    }

    public function testCalculateDailyEToThrowsWhenApiResponseMissingRadiation(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn(['elevation' => 74.0, 'daily' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.invalid_api_structure');

        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $this->validArchivePayload(), $httpClientMock);
    }

    public function testCalculateDailyEToThrowsOnNegativeRadiationValue(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn(['elevation' => 74.0, 'daily' => ['shortwave_radiation_sum' => [-5.0]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.negative_radiation');

        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $this->validArchivePayload(), $httpClientMock);
    }

    public function testCalculateDailyEToThrowsOnTemperatureOutOfRange(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.temperature_out_of_range');

        // temp_min (35) > temp_max (20) triggers the range check before any API call
        $payload = array_merge($this->validArchivePayload(), ['temp_min' => 35.0, 'temp_max' => 20.0]);
        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $payload, $httpClientMock);
    }

    // -------------------------------------------------------------------------
    // Edge-case tests – reveal implicit behavior and boundary conditions
    // -------------------------------------------------------------------------

    /**
     * Windstille (wind_avg = 0.0) is a valid meteorological condition.
     * The FAO-56 formula must not crash; u2 = 0.0 simply removes the aerodynamic term,
     * leaving a radiation-driven ETo result >= 0.
     */
    public function testCalculateDailyEToWithZeroWindSpeedCompletesSuccessfully(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn([
                'elevation' => 74.0,
                'daily'     => ['shortwave_radiation_sum' => [25.0]]
            ]);

        $payload = array_merge($this->validArchivePayload(), ['wind_avg' => 0.0]);
        $result = $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $payload, $httpClientMock);

        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(0.0, $result);
    }

    public function testCalculateDailyEToThrowsOnNonNumericArchiveField(): void
    {
        $calculator = new EToCalculator(new ArrayLogger());
        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->expects($this->never())->method('SendHTTPRequest');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.eto.archive_data_non_numeric');

        // A string in a numeric field must be rejected before any API call is made
        $payload = array_merge($this->validArchivePayload(), ['temp_min' => 'not-a-number']);
        $calculator->calculateDailyETo(50.83, 4.37, '2026-06-30', 181, 'Europe/Brussels', 2.0, $payload, $httpClientMock);
    }

    // -------------------------------------------------------------------------
    // fetchOpenMeteoData error handling (tested via calculateDailyETo)
    // -------------------------------------------------------------------------

    private function validArchivePayload(): array
    {
        return [
            'temp_min'     => 15.0,
            'temp_max'     => 28.0,
            'humidity_min' => 45.0,
            'humidity_max' => 85.0,
            'baro_avg'     => 1000.0,
            'wind_avg'     => 2.5
        ];
    }
}
