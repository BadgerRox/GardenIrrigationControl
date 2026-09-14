<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use PHPUnit\Framework\TestCase;

class AutoIrrigationContractsTest extends TestCase
{
    public function testAllowedRunStatesAreValidatedStrictly(): void
    {
        foreach (AutoIrrigationContracts::getAllowedRunStates() as $state) {
            $this->assertTrue(AutoIrrigationContracts::isValidRunState($state));
        }

        $this->assertFalse(AutoIrrigationContracts::isValidRunState('running'));
        $this->assertFalse(AutoIrrigationContracts::isValidRunState('PAUSE_WIND'));
        $this->assertFalse(AutoIrrigationContracts::isValidRunState(''));
    }

    public function testAutoIrrigationPhaseOneIdentifiersAreStable(): void
    {
        $this->assertSame('AutoIrrigationDaily', AutoIrrigationContracts::TIMER_IDENT_DAILY);
        $this->assertSame('AutoIrrigationStopRequested', AutoIrrigationContracts::STOP_SIGNAL_BUFFER_KEY);
        $this->assertSame('AUTO_IRRIGATION_RUN_LOCK', AutoIrrigationContracts::RUN_LOCK_KEY);
        $this->assertSame('AutoIrrigationRunState', AutoIrrigationContracts::RUN_STATE_IDENT);
    }

}
