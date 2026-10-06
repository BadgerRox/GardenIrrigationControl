<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingRainGate;
use PHPUnit\Framework\TestCase;

final class LawnCoolingRainGateTest extends TestCase
{
    private LawnCoolingRainGate $gate;

    protected function setUp(): void
    {
        $this->gate = new LawnCoolingRainGate();
    }

    public function testRainAboveTwoMillimetersBlocksCooling(): void
    {
        $result = $this->gate->evaluate(2.01);

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_ZONE_RAIN_6H_EXCEEDED', $result['reason']);
    }

    public function testRainAtThresholdAllowsCooling(): void
    {
        $result = $this->gate->evaluate(2.0);

        $this->assertTrue($result['allowed']);
    }

    public function testMissingRainValueUsesFailOpenFallback(): void
    {
        $result = $this->gate->evaluate(null);

        $this->assertTrue($result['allowed']);
        $this->assertSame('ALLOW', $result['reason']);
    }

    public function testDayCounterRainfallHandlesMidnightReset(): void
    {
        $start = new DateTimeImmutable('2026-08-28 23:00:00');
        $end = new DateTimeImmutable('2026-08-29 05:00:00');
        $rainfall = $this->gate->calculateDayCounterRainfall(
            [
                ['TimeStamp' => strtotime('2026-08-28 22:00:00'), 'Value' => 9.0],
                ['TimeStamp' => strtotime('2026-08-28 23:30:00'), 'Value' => 10.0],
                ['TimeStamp' => strtotime('2026-08-29 00:15:00'), 'Value' => 0.5],
                ['TimeStamp' => strtotime('2026-08-29 03:00:00'), 'Value' => 1.5],
            ],
            $start,
            $end
        );

        $this->assertSame(2.5, $rainfall);
    }

    public function testDayCounterReturnsNullWithoutWindowSamples(): void
    {
        $rainfall = $this->gate->calculateDayCounterRainfall(
            [['TimeStamp' => strtotime('2026-08-28 12:00:00'), 'Value' => 1.0]],
            new DateTimeImmutable('2026-08-28 18:00:00'),
            new DateTimeImmutable('2026-08-29 00:00:00')
        );

        $this->assertNull($rainfall);
    }
}
