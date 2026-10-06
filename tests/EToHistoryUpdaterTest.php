<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\EToCalculator;
use GardenIrrigationControl\Libs\EToHistoryUpdater;
use PHPUnit\Framework\TestCase;

class EToHistoryUpdaterTest extends TestCase
{
    public function testUpdateHistoryFillsMissingDayAndProvidesJsonReadyData(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $oldDate = date('Y-m-d', strtotime('-30 day'));

        $history = [
            $oldDate => 1.11
        ];

        // Pre-fill all required days except yesterday to force exactly one new calculation.
        for ($i = 2; $i <= 14; $i++) {
            $history[date('Y-m-d', strtotime('-' . $i . ' day'))] = 2.22;
        }

        $updater = new class(new ArrayLogger(), 4.327) extends EToHistoryUpdater {
            public function __construct(private ArrayLogger $arrayLogger, private float $etoValue)
            {
                parent::__construct($arrayLogger);
            }

            protected function fetchDailyAggregates(
                int $archiveID,
                int $tempID,
                int $humidityID,
                int $baroID,
                int $windID,
                int $startOfDay,
                int $endOfDay
            ): array {
                return [
                    'temp'     => [['Min' => 12.0, 'Max' => 24.0]],
                    'humidity' => [['Min' => 40.0, 'Max' => 85.0]],
                    'baro'     => [['Avg' => 1005.0]],
                    'wind'     => [['Avg' => 8.0]]
                ];
            }

            protected function calculateDailyETo(
                EToCalculator $calculator,
                float $lat,
                float $lon,
                string $dateKey,
                int $dayOfYear,
                string $timezone,
                float $windHeight,
                array $archivePayload
            ): float {
                return $this->etoValue;
            }
        };

        $result = $updater->updateHistory(
            ['latitude' => 48.2082, 'longitude' => 16.3738],
            $history,
            1,
            10,
            11,
            12,
            13,
            2.0,
            'Europe/Vienna'
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertFalse($result['hasErrors']);
        $this->assertCount(0, $result['dayErrors']);

        $this->assertArrayHasKey($yesterday, $result['history']);
        $this->assertSame(4.33, $result['history'][$yesterday]);
        $this->assertArrayNotHasKey($oldDate, $result['history']);

        // Assert that the payload is JSON-ready and not empty after successful update.
        $json = json_encode($result['history']);
        $this->assertNotFalse($json);
        $this->assertNotSame('{}', $json);
        $this->assertStringContainsString($yesterday, $json);
    }

    public function testUpdateHistoryCollectsDailyErrorsWithoutHardFail(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $history = [];

        for ($i = 2; $i <= 14; $i++) {
            $history[date('Y-m-d', strtotime('-' . $i . ' day'))] = 2.22;
        }

        $updater = new class(new ArrayLogger()) extends EToHistoryUpdater {
            public function __construct(private ArrayLogger $arrayLogger)
            {
                parent::__construct($arrayLogger);
            }

            protected function fetchDailyAggregates(
                int $archiveID,
                int $tempID,
                int $humidityID,
                int $baroID,
                int $windID,
                int $startOfDay,
                int $endOfDay
            ): array {
                return [
                    'temp'     => [['Min' => 12.0, 'Max' => 24.0]],
                    'humidity' => [['Min' => 40.0, 'Max' => 85.0]],
                    'baro'     => [['Avg' => 1005.0]],
                    'wind'     => [['Avg' => 8.0]]
                ];
            }

            protected function calculateDailyETo(
                EToCalculator $calculator,
                float $lat,
                float $lon,
                string $dateKey,
                int $dayOfYear,
                string $timezone,
                float $windHeight,
                array $archivePayload
            ): float {
                throw new RuntimeException('Simulierter Berechnungsfehler');
            }
        };

        $result = $updater->updateHistory(
            ['latitude' => 48.2082, 'longitude' => 16.3738],
            $history,
            1,
            10,
            11,
            12,
            13,
            2.0,
            'Europe/Vienna'
        );

        $this->assertFalse($result['hasChanges']);
        $this->assertTrue($result['hasErrors']);
        $this->assertCount(1, $result['dayErrors']);
        $this->assertArrayNotHasKey($yesterday, $result['history']);
        $this->assertSame('error.eto_history.calculation_failed', $result['dayErrors'][0]['translationKey']);
        $this->assertSame($yesterday, $result['dayErrors'][0]['parameters'][0]);
    }

    public function testUpdateHistoryReportsSpecificMissingArchiveData(): void
    {
        $logger = new ArrayLogger();
        $updater = new class($logger) extends EToHistoryUpdater {
            public function __construct(private ArrayLogger $arrayLogger)
            {
                parent::__construct($arrayLogger);
            }

            protected function fetchDailyAggregates(
                int $archiveID,
                int $tempID,
                int $humidityID,
                int $baroID,
                int $windID,
                int $startOfDay,
                int $endOfDay
            ): array {
                return [
                    'temp'     => [['Min' => 12.0, 'Max' => 24.0]],
                    'humidity' => [],
                    'baro'     => [['Avg' => 1005.0]],
                    'wind'     => [['Avg' => 8.0]],
                ];
            }

            protected function calculateDailyETo(
                EToCalculator $calculator,
                float $lat,
                float $lon,
                string $dateKey,
                int $dayOfYear,
                string $timezone,
                float $windHeight,
                array $archivePayload
            ): float {
                return 1.23;
            }
        };

        $result = $updater->updateHistory(
            ['latitude' => 48.2082, 'longitude' => 16.3738],
            [],
            1,
            10,
            11,
            12,
            13,
            2.0,
            'Europe/Vienna'
        );

        $this->assertTrue($result['hasErrors']);
        $this->assertGreaterThanOrEqual(1, count($result['dayErrors']));

        $combinedErrors = json_encode($result['dayErrors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('humidity', $combinedErrors);
        $this->assertStringContainsString('0 entries', $combinedErrors);

        $logs = $logger->getLogs();
        $this->assertNotEmpty($logs);

        $debugMessages = implode(' | ', array_map(static fn (array $entry): string => $entry['message'], $logs));
        $this->assertStringContainsString('Archive gap', $debugMessages);
        $this->assertStringContainsString('humidity (ID 11)', $debugMessages);
        $this->assertStringContainsString('0 entries', $debugMessages);
    }

    public function testUpdateHistoryUsesCentralArchiveReadWrapperForBrokenAggregates(): void
    {
        $updater = new class(new ArrayLogger()) extends EToHistoryUpdater {
            public int $readCalls = 0;

            protected function readAggregatedValues(
                int $archiveID,
                int $variableID,
                int $aggregationLevel,
                int $startTime,
                int $endTime,
                int $limit
            ): array {
                $this->readCalls++;
                return [];
            }

            protected function calculateDailyETo(
                EToCalculator $calculator,
                float $lat,
                float $lon,
                string $dateKey,
                int $dayOfYear,
                string $timezone,
                float $windHeight,
                array $archivePayload
            ): float {
                return 1.5;
            }
        };

        $result = $updater->updateHistory(
            ['latitude' => 48.2082, 'longitude' => 16.3738],
            [],
            1,
            10,
            11,
            12,
            13,
            2.0,
            'Europe/Vienna'
        );

        $this->assertGreaterThanOrEqual(4, $updater->readCalls);
        $this->assertTrue($result['hasErrors']);
        $this->assertNotEmpty($result['dayErrors']);
        $dayErrors = json_encode($result['dayErrors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('0 entries', $dayErrors);
    }

    public function testUpdateHistoryThrowsOnInvalidLocationData(): void
    {
        $updater = new EToHistoryUpdater(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.eto_history.location_invalid');

        $updater->updateHistory(
            ['latitude' => null, 'longitude' => null],
            [],
            1,
            10,
            11,
            12,
            13,
            2.0,
            'Europe/Vienna'
        );
    }
}
