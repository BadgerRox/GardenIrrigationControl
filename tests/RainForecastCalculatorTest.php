<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\RainForecastCalculator;
use GardenIrrigationControl\Libs\SimpleHttpClient;
use PHPUnit\Framework\TestCase;

class RainForecastCalculatorTest extends TestCase
{
    public function testGetRainForecastAggregatesRainSumAndMaxProbability(): void
    {
        $calculator = new RainForecastCalculator(new ArrayLogger());

        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn([
                'hourly' => [
                    'precipitation'             => [0.0, 0.4, 1.1, 0.2],
                    'precipitation_probability' => [10, 35, 70, 55]
                ]
            ]);

        $result = $calculator->getRainForecast($this->validLocationData(), 'Europe/Brussels', 12, $httpClientMock);

        $this->assertSame(['rainSum' => 1.7, 'maxProb' => 70.0], $result);
    }

    public function testGetRainForecastThrowsWhenHourlyDataIsMissing(): void
    {
        $calculator = new RainForecastCalculator(new ArrayLogger());

        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn(['hourly' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.rain_forecast.invalid_api_structure');

        $calculator->getRainForecast($this->validLocationData(), 'Europe/Brussels', 12, $httpClientMock);
    }

    public function testGetRainForecastThrowsWhenNegativeValuesAreReturned(): void
    {
        $calculator = new RainForecastCalculator(new ArrayLogger());

        $httpClientMock = $this->createMock(SimpleHttpClient::class);
        $httpClientMock->method('SendHTTPRequest')
            ->willReturn([
                'hourly' => [
                    'precipitation'             => [0.1, -0.2],
                    'precipitation_probability' => [20, 40]
                ]
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error.rain_forecast.negative_values');

        $calculator->getRainForecast($this->validLocationData(), 'Europe/Brussels', 12, $httpClientMock);
    }

    public function testGetRainForecastThrowsWhenForecastHoursAreOutOfRange(): void
    {
        $calculator = new RainForecastCalculator(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.rain_forecast.forecast_hours_invalid');

        $calculator->getRainForecast($this->validLocationData(), 'Europe/Brussels', 0, null);
    }
    private function validLocationData(): array
    {
        return [
            'latitude'  => 50.836983,
            'longitude' => 4.374601
        ];
    }
}
