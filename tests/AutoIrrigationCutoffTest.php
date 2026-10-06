<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationRuntimeCallbacks;
use GardenIrrigationControl\Libs\AutoIrrigationRuntimeLoopRunner;
use GardenIrrigationControl\Libs\AutoIrrigationZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class AutoIrrigationCutoffTest extends TestCase
{
    public function testCutoffDuringPauseTriggersGlobalStop(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $terminalActions = [];

        $callbacks = AutoIrrigationRuntimeCallbacks::withDefaults(
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
                'resumeStable'          => false,
                'triggerMeanWind'       => 28.0,
                'thresholdWindMaxSpeed' => 20.0,
            ],
            static fn (): array => [
                'rainPauseTriggered' => true,
                'resumeDryStable'    => false,
                'triggerRainSum5m'   => 0.5,
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

        $runner = new AutoIrrigationRuntimeLoopRunner($planner, $callbacks);

        $result = $runner->runPreparedZonesRuntimeLoop([
            ['Name' => 'Zone A', 'Sequence' => 10],
        ]);

        $this->assertFalse($result['continue']);
        $this->assertSame('stopped', $result['outcome']);
        $this->assertSame('Daily cutoff reached.', $result['reason']);
        $this->assertSame([['Zone A', 'daily_cutoff']], $terminalActions);
    }

    public function testCutoffAcrossMidnightUsesAbsoluteTimestamp(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $tz = new DateTimeZone('Europe/Berlin');

        $runStart = new DateTimeImmutable('2026-07-22 23:50:00', $tz);
        $maxRuntimeSeconds = 20 * 60;

        $beforeCutoff = new DateTimeImmutable('2026-07-23 00:09:59', $tz);
        $afterCutoff = new DateTimeImmutable('2026-07-23 00:10:01', $tz);

        $beforeResult = $planner->evaluateDailyCutoffGate(
            $runStart->getTimestamp(),
            $maxRuntimeSeconds,
            $beforeCutoff->getTimestamp()
        );
        $afterResult = $planner->evaluateDailyCutoffGate(
            $runStart->getTimestamp(),
            $maxRuntimeSeconds,
            $afterCutoff->getTimestamp()
        );

        $this->assertFalse($beforeResult['cutoffReached']);
        $this->assertSame('cutoff_not_reached', $beforeResult['reason']);
        $this->assertTrue($afterResult['cutoffReached']);
        $this->assertSame('cutoff_reached', $afterResult['reason']);
    }

    public function testCutoffHandlesDstTransitionWithLocalTimezone(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();
        $tz = new DateTimeZone('Europe/Berlin');

        // DST spring transition day in local timezone (02:00 -> 03:00).
        $runStart = new DateTimeImmutable('2026-03-29 01:55:00', $tz);
        $maxRuntimeSeconds = 20 * 60;
        $cutoffTimestamp = $runStart->getTimestamp() + $maxRuntimeSeconds;

        $beforeCutoff = (new DateTimeImmutable('@' . ($cutoffTimestamp - 1)))->setTimezone($tz);
        $afterCutoff = (new DateTimeImmutable('@' . ($cutoffTimestamp + 1)))->setTimezone($tz);

        $beforeResult = $planner->evaluateDailyCutoffGate(
            $runStart->getTimestamp(),
            $maxRuntimeSeconds,
            $beforeCutoff->getTimestamp()
        );
        $afterResult = $planner->evaluateDailyCutoffGate(
            $runStart->getTimestamp(),
            $maxRuntimeSeconds,
            $afterCutoff->getTimestamp()
        );

        $this->assertFalse($beforeResult['cutoffReached']);
        $this->assertSame('cutoff_not_reached', $beforeResult['reason']);
        $this->assertTrue($afterResult['cutoffReached']);
        $this->assertSame('cutoff_reached', $afterResult['reason']);
    }
}
