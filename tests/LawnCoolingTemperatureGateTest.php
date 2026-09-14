<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingTemperatureGate;
use PHPUnit\Framework\TestCase;

final class LawnCoolingTemperatureGateTest extends TestCase
{
    private LawnCoolingTemperatureGate $gate;

    protected function setUp(): void
    {
        $this->gate = new LawnCoolingTemperatureGate();
    }

    public function testAllowsFiveMinutesAtOrAboveThreshold(): void
    {
        $result = $this->gate->evaluate(
            [
                ['TimeStamp' => 3600, 'Value' => 30],
                ['TimeStamp' => 3900, 'Value' => 31],
            ],
            30,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3900')
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('ALLOW', $result['reason']);
    }

    public function testAllowsDescendingArchiveValuesWhenThresholdWasReached(): void
    {
        $result = $this->gate->evaluate(
            [
                ['TimeStamp' => 3900, 'Value' => 20],
                ['TimeStamp' => 3600, 'Value' => 20],
            ],
            20,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3900')
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('ALLOW', $result['reason']);
    }

    public function testRejectsSingleTemperatureSpike(): void
    {
        $result = $this->gate->evaluate(
            [
                ['Time' => 3600, 'Value' => 30],
                ['Time' => 3660, 'Value' => 29],
            ],
            30,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3660')
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_ZONE_TEMP_THRESHOLD_NOT_REACHED', $result['reason']);
    }

    public function testRejectsThresholdDurationShorterThanFiveMinutes(): void
    {
        $result = $this->gate->evaluate(
            [
                ['Time' => 3600, 'Value' => 30],
                ['Time' => 3899, 'Value' => 29],
            ],
            30,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3899')
        );

        $this->assertFalse($result['allowed']);
    }

    public function testRejectsWhenNoValidTemperatureValuesExist(): void
    {
        $result = $this->gate->evaluate(
            [['Time' => 3600, 'Value' => 'not-a-number']],
            30,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3900')
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_ZONE_NO_VALID_TEMPERATURE_ARCHIVE', $result['reason']);
    }

    public function testIgnoresValuesOutsideCurrentDayWindow(): void
    {
        $result = $this->gate->evaluate(
            [
                ['Time' => -300, 'Value' => 30],
                ['Time' => 3600, 'Value' => 29],
            ],
            30,
            new DateTimeImmutable('@0'),
            new DateTimeImmutable('@3900')
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('COOL_ZONE_TEMP_THRESHOLD_NOT_REACHED', $result['reason']);
    }
}
