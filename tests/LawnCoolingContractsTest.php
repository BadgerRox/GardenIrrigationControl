<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Tests;

use PHPUnit\Framework\TestCase;

// Ensure the autoloader is registered
require_once __DIR__ . '/../libs/Autoload.php';
gardenIrrigationControlRegisterAutoload();

use GardenIrrigationControl\Libs\LawnCoolingContracts;

class LawnCoolingContractsTest extends TestCase
{
    /**
     * Test 1: LawnCoolingContracts definiert alle erforderlichen Timer-Idents.
     */
    public function testTimerIdentsAreDefined(): void
    {
        $this->assertNotEmpty(LawnCoolingContracts::TIMER_IDENT_DAILY);
        $this->assertNotEmpty(LawnCoolingContracts::TIMER_IDENT_PRECHECK);
        $this->assertSame('LawnCoolingDaily', LawnCoolingContracts::TIMER_IDENT_DAILY);
        $this->assertSame('LawnCoolingPrecheckTimer', LawnCoolingContracts::TIMER_IDENT_PRECHECK);
    }

    /**
     * Test 2: Precheck-Offset ist 5 Minuten.
     */
    public function testPrecheckOffsetIsCorrect(): void
    {
        $this->assertSame(5, LawnCoolingContracts::PRECHECK_OFFSET_MINUTES);
    }

    /**
     * Test 3: Alle erforderlichen Buffer-Keys sind definiert.
     */
    public function testBufferKeysAreDefined(): void
    {
        $this->assertNotEmpty(LawnCoolingContracts::STOP_SIGNAL_BUFFER_KEY);
        $this->assertNotEmpty(LawnCoolingContracts::SKIP_SIGNAL_BUFFER_KEY);
        $this->assertNotEmpty(LawnCoolingContracts::PRECHECK_ACTIVE_BUFFER_KEY);
        $this->assertSame('LawnCoolingStopRequested', LawnCoolingContracts::STOP_SIGNAL_BUFFER_KEY);
        $this->assertSame('LawnCoolingSkipCurrentRun', LawnCoolingContracts::SKIP_SIGNAL_BUFFER_KEY);
        $this->assertSame('LawnCoolingPrecheckActive', LawnCoolingContracts::PRECHECK_ACTIVE_BUFFER_KEY);
    }

    /**
     * Test 4: Skipping is allowed before the first valve opens; after that, it is treated as a hard stop.
     */
    public function testSkipSemanticsBeforeAndAfterFirstValveOpen(): void
    {
        $this->assertTrue(LawnCoolingContracts::isSkipSignalAllowedBeforeFirstValve(false));
        $this->assertFalse(LawnCoolingContracts::isSkipSignalAllowedBeforeFirstValve(true));
        $this->assertTrue(LawnCoolingContracts::requiresHardStopAfterFirstValve(true));
        $this->assertFalse(LawnCoolingContracts::requiresHardStopAfterFirstValve(false));
    }

    /**
     * Test 5: Run States sind korrekt definiert.
     */
    public function testRunStatesAreDefined(): void
    {
        $states = LawnCoolingContracts::allowedRunStates();
        $this->assertContains(LawnCoolingContracts::RUN_STATE_PRECHECK, $states);
        $this->assertContains(LawnCoolingContracts::RUN_STATE_RUNNING, $states);
        $this->assertContains(LawnCoolingContracts::RUN_STATE_STOPPED, $states);
        $this->assertContains(LawnCoolingContracts::RUN_STATE_FINISHED, $states);
        $this->assertCount(4, $states);
        // Verify that there is NO PAUSED state (unlike AutoIrrigation)
        $this->assertNotContains('PAUSED', $states);
    }

    /**
     * Test 5: Lock-Key ist definiert.
     */
    public function testLockKeyIsDefined(): void
    {
        $this->assertNotEmpty(LawnCoolingContracts::RUN_LOCK_KEY);
        $this->assertSame('LAWN_COOLING_RUN_LOCK', LawnCoolingContracts::RUN_LOCK_KEY);
    }

    /**
     * Test 6: Run State Ident ist definiert.
     */
    public function testRunStateIdentIsDefined(): void
    {
        $this->assertNotEmpty(LawnCoolingContracts::RUN_STATE_IDENT);
        $this->assertSame('LawnCoolingRunState', LawnCoolingContracts::RUN_STATE_IDENT);
    }

    /**
     * Test 7: allowedRunStates() ist korrekt geordnet.
     */
    public function testRunStatesOrderIsCorrect(): void
    {
        $states = LawnCoolingContracts::allowedRunStates();
        $expectedOrder = [
            LawnCoolingContracts::RUN_STATE_PRECHECK,
            LawnCoolingContracts::RUN_STATE_RUNNING,
            LawnCoolingContracts::RUN_STATE_STOPPED,
            LawnCoolingContracts::RUN_STATE_FINISHED,
        ];
        $this->assertSame($expectedOrder, $states);
    }

    public function testStopReasonsAreGroupedAndUnique(): void
    {
        $globalReasons = LawnCoolingContracts::globalStopReasons();
        $zoneReasons = LawnCoolingContracts::zoneStopReasons();

        $this->assertCount(5, $globalReasons);
        $this->assertCount(7, $zoneReasons);
        $this->assertCount(count($globalReasons), array_unique($globalReasons));
        $this->assertCount(count($zoneReasons), array_unique($zoneReasons));
        $this->assertSame([], array_intersect($globalReasons, $zoneReasons));
        $this->assertContains(LawnCoolingContracts::STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED, $globalReasons);
        $this->assertContains(LawnCoolingContracts::STOP_REASON_ZONE_VALVE_OPEN_FAILED, $zoneReasons);
        $this->assertContains(LawnCoolingContracts::STOP_REASON_ZONE_SKIP_TODAY, $zoneReasons);
    }
}
