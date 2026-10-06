<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use GardenIrrigationControl\Libs\AutoIrrigationRuntimeCallbacks;
use GardenIrrigationControl\Libs\AutoIrrigationRuntimeLoopRunner;
use GardenIrrigationControl\Libs\AutoIrrigationZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class AutoIrrigationRuntimeLoopRunnerTest extends TestCase
{
    public function testRunPreparedZonesRuntimeLoopKeepsCurrentZoneActiveWhenAllGuardsStayTrue(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $debugMessages = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message) use (&$debugMessages): void
            {
                $debugMessages[] = [$context, $message];
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
        $this->assertSame('The current zone remains active and will be evaluated again on the next runtime tick.', $result['reason']);
        $this->assertNotEmpty($debugMessages);
    }

    public function testRunPreparedZonesRuntimeLoopStopsWhenAutoModeTurnsOff(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => false,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('Automatic mode was disabled during the runtime phase.', $result['reason']);
    }

    public function testRunPreparedZonesRuntimeLoopStopsWhenStopSignalIsSet(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => true,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('Stop signal detected in the runtime loop.', $result['reason']);
    }

    public function testRunPreparedZonesRuntimeLoopUsesFallbackZoneLabelWhenNameAndSequenceMissing(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $debugMessages = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message) use (&$debugMessages): void
            {
                $debugMessages[] = [$context, $message];
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $runner->runPreparedZonesRuntimeLoop([
            ['Active' => 1],
        ]);

        $messages = array_map(static fn (array $entry): string => $entry[1], $debugMessages);
        $containsFallbackLabel = false;
        foreach ($messages as $message) {
            if (str_contains($message, 'unnamed (Seq -1)')) {
                $containsFallbackLabel = true;
                break;
            }
        }

        $this->assertTrue($containsFallbackLabel);
    }

    public function testRunPreparedZonesRuntimeLoopSetsPausedWindWhenTriggerActiveAndResumeNotStable(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => true,
                'resumeStable'          => false,
                'triggerMeanWind'       => 28.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('paused_wind', $result['outcome']);
        $this->assertSame(
            [[AutoIrrigationContracts::RUN_STATE_PAUSED_WIND, 'Wind pause active: wind limit exceeded.']],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopReturnsZoneActiveWhenWindResumeWindowStable(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => true,
                'resumeStable'          => true,
                'pauseActive'           => false,
                'triggerMeanWind'       => 24.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PAUSED_WIND, 'Wind pause active: wind limit exceeded.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Wind pause ended: resume window is stable.'],
            ],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopKeepsPausedWindWhenTriggerDropsButPauseStaysActive(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'pauseActive'           => true,
                'triggerMeanWind'       => 7.8,
                'thresholdWindMaxSpeed' => 10.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('paused_wind', $result['outcome']);
        $this->assertSame('Wind pause remains active; the resume window is not stable yet.', $result['reason']);
        $this->assertSame([], $states);
    }

    public function testRunPreparedZonesRuntimeLoopResumesWindPauseWhenTriggerDropsAndResumeWindowStabilizes(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => true,
                'pauseActive'           => true,
                'triggerMeanWind'       => 7.8,
                'thresholdWindMaxSpeed' => 10.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
        $this->assertSame(
            [[AutoIrrigationContracts::RUN_STATE_RUNNING, 'Wind pause ended: resume window is stable.']],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopKeepsCurrentZoneActiveUntilTargetReachedBeforeNextZone(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $visitedZones = [];
        $targetSnapshotsByZone = [
            'Zone A' => [
                ['elapsedWateringMinutes' => 0.0, 'runtimeEffectiveMinutes' => 10.0],
                ['elapsedWateringMinutes' => 10.0, 'runtimeEffectiveMinutes' => 10.0],
            ],
            'Zone B' => [
                ['elapsedWateringMinutes' => 10.0, 'runtimeEffectiveMinutes' => 10.0],
            ],
        ];
        $targetSnapshotCallCount = [
            'Zone A' => 0,
            'Zone B' => 0,
        ];
        $terminalActions = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message) use (&$visitedZones): void
            {
                if (preg_match('/Zone (Zone [AB]) \(Seq \d+\): evaluating target_reached_gate\./', $message, $matches) === 1) {
                    $visitedZones[] = $matches[1];
                }
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            null,
            static function (array $zone) use (&$targetSnapshotsByZone, &$targetSnapshotCallCount): array
            {
                $zoneName = (string) $zone['Name'];
                $index = $targetSnapshotCallCount[$zoneName];
                $targetSnapshotCallCount[$zoneName]++;

                return $targetSnapshotsByZone[$zoneName][$index] ?? end($targetSnapshotsByZone[$zoneName]);
            },
            null,
            static function (array $zone, string $reason) use (&$terminalActions): void
            {
                $terminalActions[] = [(string) $zone['Name'], $reason];
            }
        );

        $firstResult = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 1],
            ['Name' => 'Zone B', 'Sequence' => 2],
        ]);

        $this->assertFalse($firstResult['continue']);
        $this->assertSame('zone_active', $firstResult['outcome']);
        $this->assertSame(['Zone A'], $visitedZones);

        $secondResult = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 1],
            ['Name' => 'Zone B', 'Sequence' => 2],
        ]);

        $this->assertTrue($secondResult['continue']);
        $this->assertSame('continue', $secondResult['outcome']);
        $this->assertSame(['Zone A', 'Zone A', 'Zone B'], $visitedZones);
        $this->assertSame([
            ['Zone A', 'target_reached'],
            ['Zone B', 'target_reached'],
        ], $terminalActions);
    }

    public function testRunPreparedZonesRuntimeLoopSetsPausedRainWhenTriggerActiveAndDryWindowNotStable(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => true,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.6,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('paused_rain', $result['outcome']);
        $this->assertSame(
            [[AutoIrrigationContracts::RUN_STATE_PAUSED_RAIN, 'Rain pause active: rain limit exceeded.']],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopReturnsZoneActiveWhenRainDryWindowStable(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => true,
                'resumeDryStable'    => true,
                'pauseActive'        => false,
                'triggerRainSum5m'   => 0.5,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
        $this->assertSame(
            [
                [AutoIrrigationContracts::RUN_STATE_PAUSED_RAIN, 'Rain pause active: rain limit exceeded.'],
                [AutoIrrigationContracts::RUN_STATE_RUNNING, 'Rain pause ended: dry window is stable.'],
            ],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopKeepsPausedRainWhenTriggerDropsButPauseStaysActive(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'pauseActive'        => true,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('paused_rain', $result['outcome']);
        $this->assertSame('Rain pause remains active; the dry resume window is not stable yet.', $result['reason']);
        $this->assertSame([], $states);
    }

    public function testRunPreparedZonesRuntimeLoopResumesRainPauseWhenTriggerDropsAndDryWindowStabilizes(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $states = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason) use (&$states): void
            {
                $states[] = [$state, $reason];
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => true,
                'pauseActive'        => true,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
        $this->assertSame(
            [[AutoIrrigationContracts::RUN_STATE_RUNNING, 'Rain pause ended: dry window is stable.']],
            $states
        );
    }

    public function testRunPreparedZonesRuntimeLoopStopsGloballyWhenResumeReentryFailsWithGlobalStop(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => true,
                'resumeStable'          => true,
                'triggerMeanWind'       => 24.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => false,
                'globalStop'   => true,
                'reason'       => 'Resume re-entry global stop.',
                'debugMessage' => 'Resume re-entry global stop.',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('stopped', $result['outcome']);
        $this->assertSame('Resume re-entry global stop.', $result['reason']);
    }

    public function testRunPreparedZonesRuntimeLoopContinuesWithNextZoneWhenResumeReentryIsZoneLocalFail(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => true,
                'resumeStable'          => true,
                'triggerMeanWind'       => 24.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => false,
                'globalStop'   => false,
                'reason'       => 'Resume re-entry failed locally.',
                'debugMessage' => 'Resume re-entry failed locally.',
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
            ['Name' => 'Zone B', 'Sequence' => 20],
        ]);

        $this->assertTrue($result['continue']);
        $this->assertSame('continue', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopKeepsCurrentZoneActiveWhenSoilRuntimeConfirmWindowIsNotStable(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 1,
                'source'               => 'zone',
                'measuredSoil'         => 45.0,
                'soilMinMoisture'      => 40,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 2,
                'confirmWindowStable'  => false,
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopSkipsCurrentZoneWhenSoilRuntimeWindowIsConfirmed(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 1,
                'source'               => 'global',
                'measuredSoil'         => 44.0,
                'soilMinMoisture'      => 40,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 4,
                'confirmWindowStable'  => true,
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
            ['Name' => 'Zone B', 'Sequence' => 20],
        ]);

        $this->assertTrue($result['continue']);
        $this->assertSame('continue', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopKeepsCurrentZoneActiveWhenTargetRuntimeIsMissing(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 5.0,
                'runtimeEffectiveMinutes' => null,
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopKeepsCurrentZoneActiveWhenTargetIsNotReached(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 3.0,
                'runtimeEffectiveMinutes' => 8.0,
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('zone_active', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopSkipsZoneWhenTargetIsReached(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 8.5,
                'runtimeEffectiveMinutes' => 8.0,
            ]
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
            ['Name' => 'Zone B', 'Sequence' => 20],
        ]);

        $this->assertTrue($result['continue']);
        $this->assertSame('continue', $result['outcome']);
    }

    public function testRunPreparedZonesRuntimeLoopStopsGloballyWhenDailyCutoffIsReached(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $terminalActions = [];

        $runner = $this->createRunner(
            $planner,
            static function (string $context, string $message): void
            {
            },
            static fn (): bool => true,
            static fn (): bool => false,
            static fn (): bool => false,
            static function (string $state, string $reason): void
            {
            },
            static fn (): array => [
                'windPauseTriggered'    => false,
                'resumeStable'          => false,
                'triggerMeanWind'       => 0.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => false,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.0,
                'thresholdRainSum5m' => 0.2,
            ],
            static fn (array $zone, string $reason): array => [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'ok',
                'debugMessage' => 'ok',
            ],
            static fn (array $zone): array => [
                'mode'                 => 0,
                'source'               => 'none',
                'measuredSoil'         => null,
                'soilMinMoisture'      => 0,
                'confirmWindowSeconds' => 60,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ],
            static fn (array $zone): array => [
                'elapsedWateringMinutes'  => 0.0,
                'runtimeEffectiveMinutes' => null,
            ],
            static fn (): array => [
                'runStartTimestamp' => 1_700_000_000,
                'maxRuntimeSeconds' => 3600,
            ],
            static function (array $zone, string $reason) use (&$terminalActions): void
            {
                $terminalActions[] = [$zone['Name'] ?? 'unknown', $reason];
            }
        );

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('stopped', $result['outcome']);
        $this->assertSame('Daily cutoff reached.', $result['reason']);
        $this->assertSame([['Zone A', 'daily_cutoff']], $terminalActions);
    }

    private function createRunner(
        AutoIrrigationZoneStartPlanner $planner,
        callable $debugLogger,
        callable $isAutoEnabled,
        callable $isMaintenanceEnabled,
        callable $isStopRequested,
        callable $setRunState,
        callable $getWindPauseSnapshot,
        callable $getRainPauseSnapshot,
        callable $runResumeReentryPipeline,
        ?callable $getSoilRuntimeSnapshot = null,
        ?callable $getTargetReachedSnapshot = null,
        ?callable $getDailyCutoffSnapshot = null,
        ?callable $handleZoneTerminalAction = null,
        ?callable $handleZonePauseAction = null,
        ?callable $handleZoneResumeAction = null
    ): AutoIrrigationRuntimeLoopRunner {
        return new AutoIrrigationRuntimeLoopRunner(
            $planner,
            AutoIrrigationRuntimeCallbacks::withDefaults(
                $debugLogger,
                $isAutoEnabled,
                $isMaintenanceEnabled,
                $isStopRequested,
                $setRunState,
                $getWindPauseSnapshot,
                $getRainPauseSnapshot,
                $runResumeReentryPipeline,
                $getSoilRuntimeSnapshot,
                $getTargetReachedSnapshot,
                $getDailyCutoffSnapshot,
                $handleZoneTerminalAction,
                $handleZonePauseAction,
                $handleZoneResumeAction
            )
        );
    }
}
