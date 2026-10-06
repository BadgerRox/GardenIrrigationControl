<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ModuleErrorContexts;
use PHPUnit\Framework\TestCase;

class ModuleErrorContextsTest extends TestCase
{
    public function testNormalizeMapsLegacyValveContexts(): void
    {
        $this->assertSame(ModuleErrorContexts::VALVE_CONTROL, ModuleErrorContexts::normalize('CloseAllValves'));
        $this->assertSame(ModuleErrorContexts::VALVE_CONTROL, ModuleErrorContexts::normalize('OpenSingleValve'));
    }

    public function testNormalizeKeepsDefinedAutoIrrigationContextsStable(): void
    {
        $this->assertSame(
            ModuleErrorContexts::AUTO_IRRIGATION_FORECAST,
            ModuleErrorContexts::normalize(ModuleErrorContexts::AUTO_IRRIGATION_FORECAST)
        );

        $this->assertSame(
            ModuleErrorContexts::AUTO_IRRIGATION_WATER_BALANCE,
            ModuleErrorContexts::normalize(ModuleErrorContexts::AUTO_IRRIGATION_WATER_BALANCE)
        );

        $this->assertSame(
            ModuleErrorContexts::AUTO_IRRIGATION_SOIL,
            ModuleErrorContexts::normalize(ModuleErrorContexts::AUTO_IRRIGATION_SOIL)
        );

        $this->assertSame(
            ModuleErrorContexts::AUTO_IRRIGATION_CUTOFF,
            ModuleErrorContexts::normalize(ModuleErrorContexts::AUTO_IRRIGATION_CUTOFF)
        );

        $this->assertSame(
            ModuleErrorContexts::AUTO_IRRIGATION_RUNTIME,
            ModuleErrorContexts::normalize(ModuleErrorContexts::AUTO_IRRIGATION_RUNTIME)
        );
    }

    public function testNormalizeLeavesUnknownContextsUntouched(): void
    {
        $this->assertSame('ApplyChanges', ModuleErrorContexts::normalize('ApplyChanges'));
    }
}
