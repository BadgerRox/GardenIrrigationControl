<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ModuleErrorContexts;
use GardenIrrigationControl\Libs\ValveStartBlockerEvaluator;
use PHPUnit\Framework\TestCase;

class ValveStartBlockerEvaluatorTest extends TestCase
{
    public function testEvaluateReturnsUnblockedWhenValveControlErrorIsMissing(): void
    {
        $evaluator = new ValveStartBlockerEvaluator();

        $result = $evaluator->evaluate([], '', false);

        $this->assertFalse($result['blocked']);
        $this->assertSame('', $result['reason']);
        $this->assertSame('', $result['debugMessage']);
    }

    public function testEvaluateBlocksCloseFailures(): void
    {
        $evaluator = new ValveStartBlockerEvaluator();

        $result = $evaluator->evaluate([
            ModuleErrorContexts::VALVE_CONTROL => 401,
        ], 'close', true);

        $this->assertTrue($result['blocked']);
        $this->assertSame('Valve close failure blocks the daily start.', $result['reason']);
    }

    public function testEvaluateTreatsOpenFailuresAsSoftBlockers(): void
    {
        $evaluator = new ValveStartBlockerEvaluator();

        $result = $evaluator->evaluate([
            ModuleErrorContexts::VALVE_CONTROL => 400,
        ], 'open', false);

        $this->assertFalse($result['blocked']);
        $this->assertSame('ValveControl failure after opening is a zone-local soft blocker.', $result['reason']);
    }

    public function testDetermineLastValveOperationTypeFromValvesDetectsCloseFailure(): void
    {
        $evaluator = new ValveStartBlockerEvaluator();

        $result = $evaluator->determineLastValveOperationTypeFromValves([
            ['ValveID' => 10, 'ExpectedValue' => false],
            ['ValveID' => 11, 'ExpectedValue' => true],
        ]);

        $this->assertSame('close', $result);
    }
}
