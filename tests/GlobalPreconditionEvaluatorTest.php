<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\GlobalPreconditionEvaluator;
use GardenIrrigationControl\Libs\LawnCoolingContracts;
use GardenIrrigationControl\Libs\ModuleErrorContexts;
use PHPUnit\Framework\TestCase;

class GlobalPreconditionEvaluatorTest extends TestCase
{
    public function testConfigurationInvalidBlocksEvaluation(): void
    {
        $result = (new GlobalPreconditionEvaluator())->evaluate(true, true, false, [], '', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame(LawnCoolingContracts::STOP_REASON_GLOBAL_CONFIGURATION_INVALID, $result['reason']);
    }

    public function testDisabledAutomaticModeBlocksEvaluation(): void
    {
        $result = (new GlobalPreconditionEvaluator())->evaluate(false, false, false, [], '', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame(LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL, $result['reason']);
    }

    public function testMaintenanceModeBlocksEvaluation(): void
    {
        $result = (new GlobalPreconditionEvaluator())->evaluate(false, true, true, [], '', false);

        $this->assertFalse($result['allowed']);
        $this->assertSame(LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL, $result['reason']);
    }

    public function testHardValveBlockerBlocksEvaluation(): void
    {
        $result = (new GlobalPreconditionEvaluator())->evaluate(
            false,
            true,
            false,
            [ModuleErrorContexts::VALVE_CONTROL => 401],
            'close',
            true
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame(LawnCoolingContracts::STOP_REASON_GLOBAL_PRECHECK_FAIL, $result['reason']);
    }

    public function testValidGlobalPrerequisitesAllowEvaluation(): void
    {
        $result = (new GlobalPreconditionEvaluator())->evaluate(false, true, false, [], '', false);

        $this->assertTrue($result['allowed']);
        $this->assertSame('', $result['reason']);
    }
}
