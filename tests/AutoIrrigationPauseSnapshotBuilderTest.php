<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationPauseSnapshotBuilder;
use PHPUnit\Framework\TestCase;

class AutoIrrigationPauseSnapshotBuilderTest extends TestCase
{
    public function testBuildWindPauseSnapshotDetectsTriggerAndUnstableResumeWindow(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildWindPauseSnapshot(
            [
                ['Value' => 22.4],
                ['Value' => 18.0],
            ],
            [
                ['Value' => 15.0],
                ['Value' => 21.0],
            ],
            20.0,
            true
        );

        $this->assertSame(20.2, $result['triggerMeanWind']);
        $this->assertTrue($result['windPauseTriggered']);
        $this->assertFalse($result['resumeStable']);
        $this->assertTrue($result['pauseActive']);
        $this->assertSame(2, $result['sampleCount5m']);
        $this->assertSame(2, $result['sampleCount15m']);
    }

    public function testBuildWindPauseSnapshotReturnsStableWhenAllResumeValuesAreBelowThreshold(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildWindPauseSnapshot(
            [
                ['Value' => 8.0],
                ['Value' => 12.0],
            ],
            [
                ['Value' => 10.0],
                ['Value' => 12.5],
                ['Value' => 18.9],
            ],
            20.0,
            false
        );

        $this->assertSame(10.0, $result['triggerMeanWind']);
        $this->assertFalse($result['windPauseTriggered']);
        $this->assertTrue($result['resumeStable']);
        $this->assertFalse($result['pauseActive']);
    }

    public function testBuildWindPauseSnapshotIgnoresNonNumericValues(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildWindPauseSnapshot(
            [
                ['Value' => 'x'],
                ['Other' => 5],
            ],
            [
                ['Value' => 'invalid'],
                ['Other' => 99],
            ],
            20.0,
            false
        );

        $this->assertSame(0.0, $result['triggerMeanWind']);
        $this->assertFalse($result['windPauseTriggered']);
        $this->assertFalse($result['resumeStable']);
        $this->assertSame(2, $result['sampleCount5m']);
        $this->assertSame(2, $result['sampleCount15m']);
    }

    public function testBuildRainPauseSnapshotDetectsRainAndNonDryResumeWindow(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildRainPauseSnapshot(
            [
                ['Value' => 0.1],
                ['Value' => 0.2],
            ],
            [
                ['Value' => 0.0],
                ['Value' => 0.05],
            ],
            0.2,
            true
        );

        $this->assertSame(0.3, $result['triggerRainSum5m']);
        $this->assertTrue($result['rainPauseTriggered']);
        $this->assertFalse($result['resumeDryStable']);
        $this->assertTrue($result['pauseActive']);
        $this->assertSame(2, $result['sampleCount5m']);
        $this->assertSame(2, $result['sampleCount15m']);
    }

    public function testBuildRainPauseSnapshotReturnsDryStableForNonPositiveWindow(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildRainPauseSnapshot(
            [
                ['Value' => -0.2],
                ['Value' => 0.0],
                ['Value' => 0.15],
            ],
            [
                ['Value' => 0.0],
                ['Value' => -0.1],
            ],
            0.2,
            false
        );

        $this->assertSame(0.15, $result['triggerRainSum5m']);
        $this->assertFalse($result['rainPauseTriggered']);
        $this->assertTrue($result['resumeDryStable']);
        $this->assertFalse($result['pauseActive']);
    }

    public function testBuildRainPauseSnapshotIgnoresNonNumericValues(): void
    {
        $builder = new AutoIrrigationPauseSnapshotBuilder();

        $result = $builder->buildRainPauseSnapshot(
            [
                ['Value' => 'foo'],
                ['Other' => 1],
            ],
            [
                ['Value' => 'bar'],
                ['Other' => 2],
            ],
            0.2,
            false
        );

        $this->assertSame(0.0, $result['triggerRainSum5m']);
        $this->assertFalse($result['rainPauseTriggered']);
        $this->assertFalse($result['resumeDryStable']);
        $this->assertSame(2, $result['sampleCount5m']);
        $this->assertSame(2, $result['sampleCount15m']);
    }
}
