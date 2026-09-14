<?php

declare(strict_types=1);

use GardenIrrigationControl\Libs\ArrayLogger;
use GardenIrrigationControl\Libs\LawnCoolingZoneCandidateService;
use GardenIrrigationControl\Libs\LawnCoolingZoneStartPlanner;
use PHPUnit\Framework\TestCase;

class LawnCoolingZoneCandidateServiceTest extends TestCase
{
    public function testEvaluateCandidatesReturnsSoftBlockWhenNoCoolingZonesExist(): void
    {
        $service = new LawnCoolingZoneCandidateService();

        $result = $service->evaluateCandidates(new LawnCoolingZoneStartPlanner(), [], [], 'test');

        $this->assertFalse($result['allowed']);
        $this->assertSame([], $result['zones']);
        $this->assertSame('COOL_ZONE_NO_ACTIVE_COOLING_ZONES', $result['reason']);
    }

    public function testEvaluateCandidatesKeepsDryZonesAndSkipsWetZones(): void
    {
        $logger = new ArrayLogger();
        $service = new LawnCoolingZoneCandidateService($logger);
        $planner = new LawnCoolingZoneStartPlanner();

        $zones = [
            ['Name' => 'Wet Cooling', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 10, 'ValveVarID' => 100],
            ['Name' => 'Dry Cooling', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 20, 'ValveVarID' => 200],
        ];

        $inputs = [
            [
                'zoneLabel'       => 'Wet Cooling (Seq 10)',
                'zoneSoilValue'   => null,
                'globalSoilValue' => 45.0,
                'useGlobalSoil'   => true,
                'soilMinMoisture' => 40,
                'zoneSoilVarId'   => 0,
                'globalSoilVarId' => 456,
            ],
            [
                'zoneLabel'       => 'Dry Cooling (Seq 20)',
                'zoneSoilValue'   => 35.0,
                'globalSoilValue' => 45.0,
                'useGlobalSoil'   => true,
                'soilMinMoisture' => 40,
                'zoneSoilVarId'   => 123,
                'globalSoilVarId' => 456,
            ],
        ];

        $result = $service->evaluateCandidates($planner, $zones, $inputs, 'LawnCoolingDaily');

        $this->assertTrue($result['allowed']);
        $this->assertSame(['Dry Cooling'], array_column($result['zones'], 'Name'));
        $this->assertCount(1, $result['skippedZones']);
        $this->assertSame('soil_above_threshold_at_start', $result['skippedZones'][0]['reason']);
        $this->assertNotSame([], $logger->getLogs());
    }

    public function testEvaluateCandidatesAllowsMissingSoilSourceBypass(): void
    {
        $service = new LawnCoolingZoneCandidateService();

        $zones = [
            ['Name' => 'Cooling Without Soil', 'Active' => 1, 'UseCooling' => true, 'Sequence' => 10, 'ValveVarID' => 100],
        ];

        $inputs = [[
            'zoneLabel'       => 'Cooling Without Soil (Seq 10)',
            'zoneSoilValue'   => null,
            'globalSoilValue' => null,
            'useGlobalSoil'   => false,
            'soilMinMoisture' => 40,
            'zoneSoilVarId'   => 0,
            'globalSoilVarId' => 0,
        ]];

        $result = $service->evaluateCandidates(new LawnCoolingZoneStartPlanner(), $zones, $inputs, 'test');

        $this->assertTrue($result['allowed']);
        $this->assertSame(['Cooling Without Soil'], array_column($result['zones'], 'Name'));
        $this->assertSame([], $result['skippedZones']);
    }
}