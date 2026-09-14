<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class ZoneStartPlannerTest extends TestCase
{
    public function testPrepareActiveZonesFiltersAndSortsBySequenceWithStableFallbacks(): void
    {
        $planner = new ZoneStartPlanner();

        $zones = [
            ['Name' => 'Zone C', 'Active' => 1, 'Sequence' => 20, 'ValveVarID' => 300],
            ['Name' => 'Inactive', 'Active' => 0, 'Sequence' => 5, 'ValveVarID' => 100],
            ['Name' => 'Zone A', 'Active' => 1, 'Sequence' => '10', 'ValveVarID' => 200],
            ['Name' => 'Missing Sequence', 'Active' => 1, 'ValveVarID' => 999],
            ['Name' => 'Zone B', 'Active' => 1, 'Sequence' => 20, 'ValveVarID' => 100],
        ];

        $preparedZones = $planner->prepareActiveZones($zones);

        $this->assertSame(['Zone A', 'Zone B', 'Zone C'], array_column($preparedZones, 'Name'));
        $this->assertSame([10, 20, 20], array_column($preparedZones, 'Sequence'));
    }

    public function testPrepareActiveZonesAppliesAdditionalDomainFilter(): void
    {
        $planner = new ZoneStartPlanner();

        $zones = [
            ['Name' => 'Auto Only', 'Active' => 1, 'Sequence' => 10, 'ValveVarID' => 100, 'UseCooling' => false],
            ['Name' => 'Cooling', 'Active' => 1, 'Sequence' => 20, 'ValveVarID' => 200, 'UseCooling' => true],
        ];

        $preparedZones = $planner->prepareActiveZones(
            $zones,
            static fn (array $zone): bool => isset($zone['UseCooling']) && (bool) $zone['UseCooling']
        );

        $this->assertCount(1, $preparedZones);
        $this->assertSame('Cooling', $preparedZones[0]['Name']);
    }

    public function testEvaluateSoilStartGateBypassesWithoutActiveSource(): void
    {
        $planner = new ZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(null, null, false, 40, 0, 0);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('none', $result['source']);
        $this->assertNull($result['measuredSoil']);
        $this->assertSame('soil_source_missing', $result['reason']);
    }

    public function testEvaluateSoilStartGatePrefersZoneSensorAndAllowsDrySoil(): void
    {
        $planner = new ZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(35.0, 55.0, true, 40, 123, 456);

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['skipZone']);
        $this->assertSame('zone', $result['source']);
        $this->assertSame(35.0, $result['measuredSoil']);
        $this->assertSame('soil_below_threshold', $result['reason']);
    }

    public function testEvaluateSoilStartGateUsesAllowedGlobalSensorAndSkipsWetSoil(): void
    {
        $planner = new ZoneStartPlanner();

        $result = $planner->evaluateSoilStartGate(null, 45.0, true, 40, 0, 456);

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['skipZone']);
        $this->assertSame('global', $result['source']);
        $this->assertSame(45.0, $result['measuredSoil']);
        $this->assertSame('soil_above_threshold_at_start', $result['reason']);
    }
}