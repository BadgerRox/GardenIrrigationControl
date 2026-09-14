<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\AutoIrrigationContracts;
use GardenIrrigationControl\Libs\AutoIrrigationZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class AutoIrrigationZoneStartPlannerTest extends TestCase
{
    public function testGetRuntimeLoopCheckOrderMatchesSpecificationSequence(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $this->assertSame(
            [
                'recheck_global_preconditions',
                'enforce_daily_cutoff',
                'wind_pause_gate',
                'rain_pause_gate',
                'soil_runtime_gate_if_mode_1',
                'target_reached_gate',
            ],
            $planner->getRuntimeLoopCheckOrder()
        );
    }

    public function testEvaluateStartPrecheckBlocksDisabledAutoMode(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateStartPrecheck(false, false, true);

        $this->assertFalse($result['allowed']);
        $this->assertSame(AutoIrrigationContracts::RUN_STATE_STOPPED, $result['terminalState']);
        $this->assertSame('Automatic mode is disabled.', $result['reason']);
    }

    public function testEvaluateStartPrecheckBlocksHardValveControlError(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateStartPrecheck(
            true,
            false,
            true,
            true,
            'Valve close failure blocks the daily start.',
            'Hard start check: an active valve close failure prevents the daily run.'
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame(AutoIrrigationContracts::RUN_STATE_STOPPED, $result['terminalState']);
        $this->assertSame('Valve close failure blocks the daily start.', $result['reason']);
        $this->assertSame('Hard start check: an active valve close failure prevents the daily run.', $result['debugMessage']);
    }

    public function testPrepareActiveZonesFiltersAndSortsBySequence(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $zones = [
            ['Name' => 'Zone C', 'Active' => 1, 'Sequence' => 30, 'ValveVarID' => 300],
            ['Name' => 'Zone B', 'Active' => 1, 'Sequence' => '10', 'ValveVarID' => 200],
            ['Name' => 'Inactive', 'Active' => 0, 'Sequence' => 5, 'ValveVarID' => 999],
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => 20, 'ValveVarID' => 100],
            ['Name' => 'Missing Sequence', 'Active' => 1, 'ValveVarID' => 400],
        ];

        $preparedZones = $planner->prepareActiveZones($zones);

        $this->assertCount(3, $preparedZones);
        $this->assertSame(['Zone B', 'Zone A', 'Zone C'], array_column($preparedZones, 'Name'));
        $this->assertSame([10, 20, 30], array_column($preparedZones, 'Sequence'));
    }

    public function testEvaluateForecastGateFailsOpenOnMissingForecast(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateForecastGate(true, [], 6);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['globalStop']);
        $this->assertTrue($result['failOpen']);
        $this->assertSame('forecast_error_fail_open', $result['reason']);
        $this->assertSame(6, $result['resolvedForecastHours']);
    }

    public function testEvaluateForecastGateStopsWhenThresholdsMatch(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateForecastGate(true, ['rainSum' => 5.5, 'maxProb' => 70.0], null, 12, 70, 5.0);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['globalStop']);
        $this->assertFalse($result['failOpen']);
        $this->assertTrue($result['matched']);
        $this->assertSame('forecast_match', $result['reason']);
        $this->assertSame(12, $result['resolvedForecastHours']);
    }

    public function testEvaluateForecastGateUsesResolvedParametersInMatchRule(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateForecastGate(true, ['rainSum' => 5.5, 'maxProb' => 70.0], 8, 12, 70, 5.0);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['globalStop']);
        $this->assertTrue($result['matched']);
        $this->assertSame(8, $result['resolvedForecastHours']);
        $this->assertSame(70, $result['probabilityThresholdPercent']);
        $this->assertSame(5.0, $result['rainAmountThresholdMm']);
    }

    public function testEvaluateDataConsistencyGateSkipsZoneOnFailedRefresh(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateDataConsistencyGate(true, false);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('data_consistency_refresh_failed', $result['reason']);
    }

    public function testComputeRequiredMmSkipsWhenInputIsMissing(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->computeRequiredMm(null, 0.3, 1.2);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('required_mm_input_missing', $result['reason']);
        $this->assertNull($result['requiredMm']);
    }

    public function testComputeRequiredMmReturnsPositiveDemandForNegativeStorageDeficit(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->computeRequiredMm(-4.0, 0.5, 1.0);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame(2.5, $result['requiredMm']);
        $this->assertSame('storage_negative', $result['reason']);
    }

    public function testDeriveRuntimeCapsValuesAboveZoneMaximum(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->deriveRuntime(18.0, 1.0, 1.0, 12);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertTrue($result['runtimeCapped']);
        $this->assertSame(18.0, $result['runtimeRawMinutes']);
        $this->assertSame(12.0, $result['runtimeEffectiveMinutes']);
        $this->assertSame('runtime_capped_to_zone_maximum', $result['reason']);
    }

    public function testDeriveRuntimeSkipsBelowMinimum(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->deriveRuntime(0.5, 1.0, 1.0, 12);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('runtime_below_minimum', $result['reason']);
    }

    public function testEvaluateIntervalGateAllowsFirstRunWithoutHistory(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateIntervalGate(null, 3, '2026-07-20', 6.0, 12);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertFalse($result['edgeOverrideUsed']);
        $this->assertTrue($result['intervalDue']);
        $this->assertSame('interval_first_run', $result['reason']);
    }

    public function testEvaluateIntervalGateSkipsWhenIntervalIsNotDue(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateIntervalGate('2026-07-19', 3, '2026-07-20', 6.0, 12);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertFalse($result['edgeOverrideUsed']);
        $this->assertFalse($result['intervalDue']);
        $this->assertSame('interval_not_due', $result['reason']);
    }

    public function testEvaluateIntervalGateUsesEdgeOverrideWhenRawRuntimeExceedsZoneMaximum(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateIntervalGate('2026-07-19', 3, '2026-07-20', 15.0, 12);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertTrue($result['edgeOverrideUsed']);
        $this->assertFalse($result['intervalDue']);
        $this->assertSame('interval_not_due_edge_override', $result['reason']);
    }

    public function testEvaluateStopSignalCheckpointBlocksWhenRequested(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateStopSignalCheckpoint(true, 'pre_zone_start');

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['globalStop']);
        $this->assertSame('stop_signal_requested', $result['reason']);
    }

    public function testEvaluateStopSignalCheckpointAllowsWhenClear(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateStopSignalCheckpoint(false, 'zone_checkpoint');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['globalStop']);
        $this->assertSame('stop_signal_clear', $result['reason']);
    }

    public function testEvaluateSoilStartGateBypassesWithoutActiveSource(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(null, null, false, 40, 0, 0);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('none', $result['source']);
        $this->assertNull($result['measuredSoil']);
        $this->assertSame('soil_source_missing', $result['reason']);
    }

    public function testEvaluateSoilStartGatePrefersZoneSourceOverGlobal(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(35.0, 20.0, true, 40, 123, 456);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('zone', $result['source']);
        $this->assertSame(35.0, $result['measuredSoil']);
        $this->assertSame('soil_below_threshold', $result['reason']);
    }

    public function testEvaluateSoilStartGateSkipsWhenMeasuredAboveThreshold(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(null, 45.0, true, 40, 0, 456);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('global', $result['source']);
        $this->assertSame(45.0, $result['measuredSoil']);
        $this->assertSame('soil_above_threshold_at_start', $result['reason']);
    }

    public function testEvaluateSoilRuntimeGateBypassesWhenModeIsNotOne(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilRuntimeGate(0, 'zone', 55.0, 40, true, 5, 60);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('soil_runtime_mode_disabled', $result['reason']);
    }

    public function testEvaluateSoilRuntimeGateKeepsRunningWhenConfirmWindowPending(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilRuntimeGate(1, 'zone', 45.0, 40, false, 2, 60);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('soil_runtime_confirm_window_pending', $result['reason']);
    }

    public function testEvaluateSoilRuntimeGateStopsZoneWhenThresholdConfirmedForWindow(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateSoilRuntimeGate(1, 'global', 44.0, 40, true, 4, 60);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('soil_runtime_above_threshold_confirmed', $result['reason']);
        $this->assertSame('global', $result['source']);
        $this->assertSame(44.0, $result['measuredSoil']);
    }

    public function testEvaluateTargetReachedGateBypassesWhenRuntimeTargetMissing(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateTargetReachedGate(3.0, null);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertFalse($result['targetReached']);
        $this->assertSame('target_runtime_missing', $result['reason']);
    }

    public function testEvaluateTargetReachedGateAllowsWhenElapsedIsBelowTarget(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateTargetReachedGate(4.0, 7.5);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertFalse($result['targetReached']);
        $this->assertSame('target_not_reached', $result['reason']);
    }

    public function testEvaluateTargetReachedGateStopsZoneWhenElapsedReachesTarget(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateTargetReachedGate(8.0, 8.0);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertTrue($result['targetReached']);
        $this->assertSame('target_reached', $result['reason']);
    }

    public function testEvaluateDailyCutoffGateAllowsWhenNowIsBeforeCutoff(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateDailyCutoffGate(1_700_000_000, 3600, 1_700_000_500);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['globalStop']);
        $this->assertFalse($result['cutoffReached']);
        $this->assertSame('cutoff_not_reached', $result['reason']);
    }

    public function testEvaluateDailyCutoffGateStopsWhenNowExceedsCutoff(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateDailyCutoffGate(1_700_000_000, 3600, 1_700_003_601);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['globalStop']);
        $this->assertTrue($result['cutoffReached']);
        $this->assertSame('cutoff_reached', $result['reason']);
    }

    public function testEvaluateRemainingDailyRuntimeGateSkipsWhenRemainingTimeIsBelowMinimum(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateRemainingDailyRuntimeGate(1_700_000_000, 180, 1.5, 1_700_000_120);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame(60, $result['remainingRuntimeSeconds']);
        $this->assertSame(90.0, $result['minimumRuntimeSeconds']);
        $this->assertSame('remaining_daily_runtime_below_minimum', $result['reason']);
    }

    public function testEvaluateRemainingDailyRuntimeGateAllowsWhenRemainingTimeMeetsMinimum(): void
    {
        $planner = new AutoIrrigationZoneStartPlanner();

        $result = $planner->evaluateRemainingDailyRuntimeGate(1_700_000_000, 180, 1.5, 1_700_000_090);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame(90, $result['remainingRuntimeSeconds']);
        $this->assertSame('remaining_runtime_sufficient', $result['reason']);
    }
}
