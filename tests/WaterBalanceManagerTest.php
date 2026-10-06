<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\WaterBalanceManager;
use PHPUnit\Framework\TestCase;

if (!function_exists('AC_GetLoggedValues')) {
    /**
     * Test stub for archive log reads.
     *
     * @return array<int, array<string, mixed>>
     */
    function AC_GetLoggedValues(int $archiveId, int $varId, int $startTime, int $endTime, int $limit = 10000): array
    {
        $values = $GLOBALS['__acLoggedValuesMock'] ?? [];
        $filtered = [];

        foreach ($values as $entry) {
            if (!is_array($entry) || !isset($entry['TimeStamp']) || !is_numeric($entry['TimeStamp'])) {
                continue;
            }

            $timeStamp = (int) $entry['TimeStamp'];
            if ($timeStamp >= $startTime && $timeStamp <= $endTime) {
                $filtered[] = $entry;
            }

            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }
}

/**
 * Class WaterBalanceManagerTest
 * * Verifies the mathematical scaling and extraction logic within the WaterBalanceManager.
 */
class WaterBalanceManagerTest extends TestCase
{
    public function testGetRainfallFromDayCounterUsesLastValueUntilEndTime(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-07 09:00:00'), 'Value' => 4.1],
            ['TimeStamp' => strtotime('2026-07-07 12:00:00'), 'Value' => 6.7],
            ['TimeStamp' => strtotime('2026-07-07 15:00:00'), 'Value' => 8.9],
        ];

        $manager = new WaterBalanceManager(new ArrayLogger());
        $result = $manager->getRainfallFromDayCounter(12345, 67890, '2026-07-07 13:00:00');

        $this->assertSame(6.7, $result);
    }

    public function testGetRainfallFromDayCounterReturnsZeroWhenNoSameDayValueExists(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-06 23:50:00'), 'Value' => 5.5],
        ];

        $manager = new WaterBalanceManager(new ArrayLogger());
        $result = $manager->getRainfallFromDayCounter(12345, 67890, '2026-07-07 13:00:00');

        $this->assertSame(0.0, $result);
    }

    public function testGetRainfallForDateIntegratesLoggedValuesAcrossPeriod(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-06 23:00:00'), 'Value' => 0.6],
            ['TimeStamp' => strtotime('2026-07-07 00:30:00'), 'Value' => 1.2],
        ];

        $manager = new WaterBalanceManager(new ArrayLogger());
        $result = $manager->getRainfallForDate(12345, 67890, '2026-07-07 00:00:00', '2026-07-07 01:00:00');

        $this->assertSame(0.9, $result);
    }

    public function testGetRainfallForDateIgnoresValuesOlderThan24HoursBeforeStart(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-05 23:00:00'), 'Value' => 5.0],
            ['TimeStamp' => strtotime('2026-07-07 00:30:00'), 'Value' => 1.0],
        ];

        $manager = new WaterBalanceManager(new ArrayLogger());
        $result = $manager->getRainfallForDate(12345, 67890, '2026-07-07 00:00:00', '2026-07-07 01:00:00');

        $this->assertSame(0.5, $result);
    }

    public function testGetRainfallForDateReturnsZeroWhenNoLoggedValuesExist(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [];

        $manager = new WaterBalanceManager(new ArrayLogger());
        $result = $manager->getRainfallForDate(12345, 67890, '2026-07-07 00:00:00', '2026-07-07 01:00:00');

        $this->assertSame(0.0, $result);
    }

    public function testGetRainfallForDateThrowsOnInvalidRange(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [];

        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.rain_history.end_before_start');

        $manager->getRainfallForDate(12345, 67890, '2026-07-07 01:00:00', '2026-07-07 00:00:00');
    }

    public function testGetRainfallForDateThrowsOnInvalidDate(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [];

        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.rain_history.invalid_datetime');

        $manager->getRainfallForDate(12345, 67890, 'invalid-start', '2026-07-07 00:00:00');
    }

    public function testGetRainfallForDateThrowsOnNonStrictDateTimeFormat(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [];

        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.rain_history.invalid_datetime');

        $manager->getRainfallForDate(12345, 67890, '2026-7-7 00:00:00', '2026-07-07 01:00:00');
    }

    public function testGetRainfallFromDayCounterThrowsOnNonStrictDateTimeFormat(): void
    {
        $GLOBALS['__acLoggedValuesMock'] = [];

        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.water_balance.datetime_invalid');

        $manager->getRainfallFromDayCounter(12345, 67890, '2026-7-7 13:00:00');
    }

    /**
     * Tests the calculation of scaled ETo for a specific zone using historical data,
     * including microclimate factor applications (Slope and Orientation) and debug logging.
     */
    public function testCalculateScaledEToForZone(): void
    {
        $logger = new ArrayLogger();
        $manager = new WaterBalanceManager($logger);

        // Target configurations for the test run
        $valveVarId = 48734;
        $date = '2026-06-30';

        $zonesTree = [
            [
                'Active'                     => 1,
                'Sequence'                   => 1,
                'Name'                       => 'TestZone',
                'sprinklerPrecipitationRate' => 0.35,
                'Slope'                      => 45.0,
                'Orientation'                => 'E',
                'ValveVarID'                 => 48734,
                'SoilMoistureVarID'          => 0,
                'UseGlobalSoilMoisture'      => 1,
                'UseCooling'                 => 0
            ]
        ];

        $etoHistory = [
            '2026-06-24' => 5.21,
            '2026-06-25' => 5.12,
            '2026-06-26' => 5.62,
            '2026-06-27' => 5.89,
            '2026-06-28' => 5.67,
            '2026-06-29' => 6.12,
            '2026-06-30' => 5.17 // Target base ETo for the calculation
        ];

        $baselineETo = 3.6;

        // Intercept internal calculator debug logs using ArrayLogger
        $logger = new ArrayLogger();

        // Execute the unit under test
        $result = $manager->calculateScaledEToForZone(
            $valveVarId,
            $date,
            $zonesTree,
            $etoHistory,
            $baselineETo,
            1.0
        );

        // Stream internal processing logs directly to the test runner stdout/terminal output
        echo "\n\n--- INTERNAL WATER BALANCE MANAGER METRICS ---\n";
        foreach ($logger->getLogs() as $log) {
            echo sprintf("[%s] %s\n", $log['context'], $log['message']);
        }
        echo "----------------------------------------------\n\n";

        /**
         * Mathematical verification:
         * Base ETo (5.17) * Sun Factor 'E' (1.05) * Slope Factor 45% (1.225) = 6.6499625
         * Rounded to 4 decimal places by the engine = 6.6500
         */
        $expectedETo = 6.6500;

        $this->assertEqualsWithDelta(
            $expectedETo,
            $result['calculatedETo'],
            0.0002,
            'The calculated ETo deviates from the expected microclimate reference value!'
        );

        $this->assertFalse($result['usedFallback']);
    }

    public function testCalculateScaledEToMirrorsOrientationOnSouthernHemisphere(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $northernResult = $manager->calculateScaledETo(5.17, 'N', 45.0, false);
        $southernResult = $manager->calculateScaledETo(5.17, 'S', 45.0, true);

        $this->assertEqualsWithDelta($northernResult, $southernResult, 0.00001);
    }

    public function testCalculateScaledEToForZoneMarksFallbackUsage(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $result = $manager->calculateScaledEToForZone(
            48734,
            '2026-06-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'E',
                    'Slope'       => 45.0,
                ]
            ],
            [],
            3.6,
            1.0
        );

        $this->assertTrue($result['usedFallback']);
        $this->assertIsFloat($result['calculatedETo']);
    }

    public function testCalculateScaledEToForZoneThrowsOnNonStrictDateFormat(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.water_balance.date_invalid');

        $manager->calculateScaledEToForZone(
            48734,
            '2026-6-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'E',
                    'Slope'       => 45.0,
                ]
            ],
            ['2026-06-30' => 5.17],
            3.6,
            1.0
        );
    }

    public function testCalculateScaledEToForZoneThrowsOnInvalidOrientation(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.water_balance.orientation_invalid');

        $manager->calculateScaledEToForZone(
            48734,
            '2026-06-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'X',
                    'Slope'       => 45.0,
                ]
            ],
            ['2026-06-30' => 5.17],
            3.6,
            1.0
        );
    }

    public function testCalculateScaledEToForZoneThrowsOnInvalidSlope(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.water_balance.slope_invalid');

        $manager->calculateScaledEToForZone(
            48734,
            '2026-06-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'E',
                    'Slope'       => 81.0,
                ]
            ],
            ['2026-06-30' => 5.17],
            3.6,
            1.0
        );
    }

    public function testCalculateScaledEToForZoneAppliesCropCoefficient(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $result = $manager->calculateScaledEToForZone(
            48734,
            '2026-06-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'E',
                    'Slope'       => 45.0,
                ]
            ],
            ['2026-06-30' => 5.17],
            3.6,
            0.75
        );

        $this->assertEqualsWithDelta(4.9874, $result['calculatedETo'], 0.0002);
        $this->assertFalse($result['usedFallback']);
    }

    public function testCalculateScaledEToForZoneThrowsOnInvalidCropCoefficient(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error.water_balance.kc_invalid');

        $manager->calculateScaledEToForZone(
            48734,
            '2026-06-30',
            [
                [
                    'ValveVarID'  => 48734,
                    'Orientation' => 'E',
                    'Slope'       => 45.0,
                ]
            ],
            ['2026-06-30' => 5.17],
            3.6,
            0.0
        );
    }

    public function testCalculateValveConsumptionIntegratesOnIntervalsInMinutes(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-07 09:00:00'), 'Value' => false],
            ['TimeStamp' => strtotime('2026-07-07 09:10:00'), 'Value' => true],
            ['TimeStamp' => strtotime('2026-07-07 09:40:00'), 'Value' => false],
        ];

        $zonesTree = [
            [
                'ValveVarID'                 => 48734,
                'sprinklerPrecipitationRate' => 0.35,
            ],
        ];

        $result = $manager->calculateValveConsumption(
            12345,
            48734,
            '2026-07-07 09:00:00',
            '2026-07-07 10:00:00',
            $zonesTree
        );

        $this->assertSame(10.5, $result);
    }

    public function testCalculateValveConsumptionUsesCarryOverStateFromBeforeStart(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-06 23:50:00'), 'Value' => true],
            ['TimeStamp' => strtotime('2026-07-07 00:20:00'), 'Value' => false],
        ];

        $zonesTree = [
            [
                'ValveVarID'                 => 48734,
                'sprinklerPrecipitationRate' => 0.5,
            ],
        ];

        $result = $manager->calculateValveConsumption(
            12345,
            48734,
            '2026-07-07 00:00:00',
            '2026-07-07 01:00:00',
            $zonesTree
        );

        $this->assertSame(10.0, $result);
    }

    public function testCalculateValveConsumptionTreatsCarryOverOlderThan48HoursAsClosed(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $GLOBALS['__acLoggedValuesMock'] = [
            ['TimeStamp' => strtotime('2026-07-03 23:50:00'), 'Value' => true],
            ['TimeStamp' => strtotime('2026-07-07 00:20:00'), 'Value' => false],
        ];

        $zonesTree = [
            [
                'ValveVarID'                 => 48734,
                'sprinklerPrecipitationRate' => 0.5,
            ],
        ];

        $result = $manager->calculateValveConsumption(
            12345,
            48734,
            '2026-07-07 00:00:00',
            '2026-07-07 01:00:00',
            $zonesTree
        );

        $this->assertSame(0.0, $result);
    }

    public function testCalculateValveConsumptionThrowsWhenValveIsNotInZonesTree(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $GLOBALS['__acLoggedValuesMock'] = [];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('error.water_balance.zone_missing');

        $manager->calculateValveConsumption(
            12345,
            48734,
            '2026-07-07 00:00:00',
            '2026-07-07 01:00:00',
            []
        );
    }

    public function testUpdateWaterBalanceHistoryRefreshesMissingFallbackAndNullEntriesAndPrunesRemovedValveKeys(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $zonesTree = [
            ['ValveVarID' => 12345],
            ['ValveVarID' => 67890],
        ];

        $currentHistory = [
            '12345' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 4.2,
                    'zoneEtoFallback' => true,
                    'rain'            => 0.0,
                    'irrigation'      => 2.0,
                ],
                [
                    'date'            => '2026-07-06',
                    'zoneEto'         => 4.0,
                    'zoneEtoFallback' => false,
                    'rain'            => null,
                    'irrigation'      => 1.0,
                ],
                [
                    'date'            => '2026-07-03',
                    'zoneEto'         => 3.8,
                    'zoneEtoFallback' => false,
                    'rain'            => 1.0,
                    'irrigation'      => 0.0,
                ],
            ],
            '99999' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 1.0,
                    'zoneEtoFallback' => false,
                    'rain'            => 0.0,
                    'irrigation'      => 0.0,
                ],
            ],
        ];

        $targetDates = ['2026-07-05', '2026-07-06'];

        $etoCalls = [];
        $rainCalls = [];
        $irrigationCalls = [];

        $result = $manager->buildWaterBalanceHistoryDataset(
            $zonesTree,
            $currentHistory,
            $targetDates,
            function (int $valveVarId, string $date) use (&$etoCalls): array
            {
                $etoCalls[] = $valveVarId . '|' . $date;
                return [
                    'calculatedETo' => 5.1,
                    'usedFallback'  => false,
                ];
            },
            function (string $startDateTime, string $endDateTime) use (&$rainCalls): ?float
            {
                $rainCalls[] = $startDateTime . '|' . $endDateTime;
                return 0.3;
            },
            function (int $valveVarId, string $startDateTime, string $endDateTime) use (&$irrigationCalls): ?float
            {
                $irrigationCalls[] = $valveVarId . '|' . $startDateTime . '|' . $endDateTime;
                return 6.7;
            }
        );

        $history = $result['history'];

        $this->assertArrayHasKey('12345', $history);
        $this->assertArrayHasKey('67890', $history);
        $this->assertArrayNotHasKey('99999', $history);

        $this->assertCount(2, $history['12345']);
        $this->assertCount(2, $history['67890']);

        $expectedCalls = [
            '12345|2026-07-05',
            '12345|2026-07-06',
            '67890|2026-07-05',
            '67890|2026-07-06',
        ];

        $this->assertSame($expectedCalls, $etoCalls);
        $this->assertCount(4, $rainCalls);
        $this->assertCount(4, $irrigationCalls);
        $this->assertSame(4, $result['refreshedEntries']);

        foreach ($history['12345'] as $entry) {
            $this->assertSame(false, $entry['zoneEtoFallback']);
            $this->assertSame(5.1, $entry['zoneEto']);
            $this->assertSame(0.3, $entry['rain']);
            $this->assertSame(6.7, $entry['irrigation']);
        }
    }

    public function testUpdateWaterBalanceHistoryKeepsCompleteEntriesWithoutRecalculation(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $zonesTree = [
            ['ValveVarID' => 12345],
        ];

        $currentHistory = [
            '12345' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 4.4,
                    'zoneEtoFallback' => false,
                    'rain'            => 0.0,
                    'irrigation'      => 2.1,
                ],
            ],
        ];

        $targetDates = ['2026-07-05'];
        $providerCallCount = 0;

        $result = $manager->buildWaterBalanceHistoryDataset(
            $zonesTree,
            $currentHistory,
            $targetDates,
            function () use (&$providerCallCount): array
            {
                $providerCallCount++;
                return [
                    'calculatedETo' => 9.9,
                    'usedFallback'  => true,
                ];
            },
            function () use (&$providerCallCount): ?float
            {
                $providerCallCount++;
                return 9.9;
            },
            function () use (&$providerCallCount): ?float
            {
                $providerCallCount++;
                return 9.9;
            }
        );

        $this->assertSame(0, $providerCallCount);
        $this->assertSame(0, $result['refreshedEntries']);
        $this->assertSame(4.4, $result['history']['12345'][0]['zoneEto']);
        $this->assertSame(false, $result['history']['12345'][0]['zoneEtoFallback']);
        $this->assertSame(0.0, $result['history']['12345'][0]['rain']);
        $this->assertSame(2.1, $result['history']['12345'][0]['irrigation']);
    }

    public function testBuildWaterStorageAnchorDatasetCreatesInitialAnchorAndClampsUpperBound(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $zonesTree = [
            ['ValveVarID' => 12345],
        ];

        $history = [
            '12345' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 20.0,
                    'zoneEtoFallback' => false,
                    'rain'            => 0.0,
                    'irrigation'      => 0.0,
                ],
                [
                    'date'            => '2026-07-06',
                    'zoneEto'         => 20.0,
                    'zoneEtoFallback' => false,
                    'rain'            => 0.0,
                    'irrigation'      => 0.0,
                ],
            ],
        ];

        $targetDates = ['2026-07-05', '2026-07-06'];

        $result = $manager->buildWaterStorageAnchorDataset(
            $zonesTree,
            $history,
            [],
            $targetDates,
            12.0
        );

        $this->assertSame(1, $result['updatedZones']);
        $this->assertSame(0.0, $result['anchor']['12345']['storageBeforeWindow']);
        $this->assertSame(-12.0, $result['anchor']['12345']['storage']);
        $this->assertSame('2026-07-06', $result['anchor']['12345']['anchorDate']);
        $this->assertSame('', $result['anchor']['12345']['lastAutoIrrigationDate']);
        $this->assertArrayHasKey('2026-07-05', $result['anchor']['12345']['bookedDays']);
        $this->assertArrayHasKey('2026-07-06', $result['anchor']['12345']['bookedDays']);
        $this->assertSame(true, $result['anchor']['12345']['bookedDays']['2026-07-06']['isFinal']);
    }

    public function testBuildWaterStorageAnchorDatasetAppliesDiffCorrectionLowerClampAndPrunes(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $zonesTree = [
            ['ValveVarID' => 12345],
        ];

        $history = [
            '12345' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 1.0,
                    'zoneEtoFallback' => true,
                    'rain'            => null,
                    'irrigation'      => null,
                ],
                [
                    'date'            => '2026-07-06',
                    'zoneEto'         => 1.0,
                    'zoneEtoFallback' => false,
                    'rain'            => 0.0,
                    'irrigation'      => 20.0,
                ],
            ],
        ];

        $currentAnchor = [
            '12345' => [
                'storage'                => -5.0,
                'anchorDate'             => '2026-07-06',
                'lastAutoIrrigationDate' => '2026-07-01',
                'bookedDays'             => [
                    '2026-07-05' => ['appliedDelta' => -5.0, 'isFinal' => true],
                    '2026-07-06' => ['appliedDelta' => -2.0, 'isFinal' => true],
                    '2026-07-01' => ['appliedDelta' => -1.0, 'isFinal' => true],
                ],
            ],
            '99999' => [
                'storage'                => 3.0,
                'anchorDate'             => '2026-07-06',
                'lastAutoIrrigationDate' => '',
                'bookedDays'             => [],
            ],
        ];

        $targetDates = ['2026-07-05', '2026-07-06'];

        $result = $manager->buildWaterStorageAnchorDataset(
            $zonesTree,
            $history,
            $currentAnchor,
            $targetDates,
            12.0
        );

        $this->assertArrayHasKey('12345', $result['anchor']);
        $this->assertArrayNotHasKey('99999', $result['anchor']);
        $this->assertSame(-6.0, $result['anchor']['12345']['storageBeforeWindow']);
        $this->assertSame(12.0, $result['anchor']['12345']['storage']);
        $this->assertSame('2026-07-06', $result['anchor']['12345']['anchorDate']);
        $this->assertSame('2026-07-01', $result['anchor']['12345']['lastAutoIrrigationDate']);
        $this->assertArrayHasKey('2026-07-05', $result['anchor']['12345']['bookedDays']);
        $this->assertArrayHasKey('2026-07-06', $result['anchor']['12345']['bookedDays']);
        $this->assertArrayNotHasKey('2026-07-01', $result['anchor']['12345']['bookedDays']);
        $this->assertSame(false, $result['anchor']['12345']['bookedDays']['2026-07-05']['isFinal']);
        $this->assertSame(19.0, $result['anchor']['12345']['bookedDays']['2026-07-06']['appliedDelta']);
    }

    public function testBuildWaterStorageAnchorDatasetKeepsUpperCapWhenProvisionalDeltaIsCorrectedDown(): void
    {
        $manager = new WaterBalanceManager(new ArrayLogger());

        $zonesTree = [
            ['ValveVarID' => 12345],
        ];

        $history = [
            '12345' => [
                [
                    'date'            => '2026-07-05',
                    'zoneEto'         => 1.0,
                    'zoneEtoFallback' => false,
                    'rain'            => 5.0,
                    'irrigation'      => 0.0,
                ],
            ],
        ];

        $currentAnchor = [
            '12345' => [
                'storageBeforeWindow'    => 12.0,
                'storage'                => 15.0,
                'anchorDate'             => '2026-07-05',
                'lastAutoIrrigationDate' => '',
                'bookedDays'             => [
                    '2026-07-05' => ['appliedDelta' => 5.0, 'isFinal' => false],
                ],
            ],
        ];

        $targetDates = ['2026-07-05'];

        $result = $manager->buildWaterStorageAnchorDataset(
            $zonesTree,
            $history,
            $currentAnchor,
            $targetDates,
            12.0
        );

        $this->assertSame(12.0, $result['anchor']['12345']['storageBeforeWindow']);
        $this->assertSame(15.0, $result['anchor']['12345']['storage']);
        $this->assertSame(4.0, $result['anchor']['12345']['bookedDays']['2026-07-05']['appliedDelta']);
        $this->assertSame(true, $result['anchor']['12345']['bookedDays']['2026-07-05']['isFinal']);
    }
}
