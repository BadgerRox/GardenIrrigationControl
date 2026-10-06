<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\LawnCoolingZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class LawnCoolingZoneStartPlannerTest extends TestCase
{
    public function testPrepareActiveCoolingZonesUsesOnlyActiveCoolingZonesInSequenceOrder(): void
    {
        $planner = new LawnCoolingZoneStartPlanner();

        $zones = [
            ['Name' => 'Inactive Cooling', 'Active' => 0, 'UseCooling' => true, 'Sequence' => 5, 'ValveVarID' => 500],
            ['Name' => 'Auto Only', 'Active' => 1, 'UseCooling' => false, 'Sequence' => 10, 'ValveVarID' => 100],
            ['Name' => 'Cooling B', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 30, 'ValveVarID' => 300],
            ['Name' => 'Cooling A', 'Active' => 1, 'UseCooling' => 1, 'Sequence' => '20', 'ValveVarID' => 200],
            ['Name' => 'Missing Sequence', 'Active' => 1, 'UseCooling' => true, 'ValveVarID' => 400],
        ];

        $preparedZones = $planner->prepareActiveCoolingZones($zones);

        $this->assertSame(['Cooling A', 'Cooling B'], array_column($preparedZones, 'Name'));
        $this->assertSame([20, 30], array_column($preparedZones, 'Sequence'));
    }

    public function testPrepareActiveCoolingZonesReturnsEmptyListWhenNoCoolingZoneIsEnabled(): void
    {
        $planner = new LawnCoolingZoneStartPlanner();

        $preparedZones = $planner->prepareActiveCoolingZones([
            ['Name' => 'Inactive', 'Active' => 0, 'UseCooling' => true, 'Sequence' => 10, 'ValveVarID' => 100],
            ['Name' => 'Auto Only', 'Active' => 1, 'UseCooling' => false, 'Sequence' => 20, 'ValveVarID' => 200],
        ]);

        $this->assertSame([], $preparedZones);
    }

    public function testCoolingSoilStartGateBypassesWithoutActiveSensorSource(): void
    {
        $planner = new LawnCoolingZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(null, null, false, 40, 0, 0);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('none', $result['source']);
        $this->assertSame('soil_source_missing', $result['reason']);
    }

    public function testCoolingSoilStartGateAllowsDrySoilAndBlocksWetSoil(): void
    {
        $planner = new LawnCoolingZoneStartPlanner();

        $dryResult = $planner->evaluateSoilStartGate(35.0, null, false, 40, 123, 0);
        $wetResult = $planner->evaluateSoilStartGate(null, 45.0, true, 40, 0, 456);

        $this->assertTrue($dryResult['allowed']);
        $this->assertFalse($dryResult['skipZone']);
        $this->assertSame('soil_below_threshold', $dryResult['reason']);

        $this->assertFalse($wetResult['allowed']);
        $this->assertTrue($wetResult['skipZone']);
        $this->assertSame('soil_above_threshold_at_start', $wetResult['reason']);
    }
}