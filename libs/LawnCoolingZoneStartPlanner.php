<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class LawnCoolingZoneStartPlanner
{
    public function __construct(private readonly ZoneStartPlanner $zoneStartPlanner = new ZoneStartPlanner())
    {
    }

    /**
     * Loads, filters and sorts the active cooling zones.
     *
     * Technical rule: reuse the neutral zone preparation and add the cooling
     * domain filter UseCooling == true.
     * Functional behavior: only explicitly enabled cooling zones enter the
     * later cooling loop, ordered by Sequence.
     * Domain rationale: lawn cooling is a targeted heat-relief feature and must
     * not accidentally run all irrigation zones.
     *
     * @param array<int, array<string, mixed>> $zonesTree
     * @return array<int, array<string, mixed>>
     */
    public function prepareActiveCoolingZones(array $zonesTree): array
    {
        return $this->zoneStartPlanner->prepareActiveZones(
            $zonesTree,
            static fn (array $zone): bool => isset($zone['UseCooling']) && (bool) $zone['UseCooling']
        );
    }

    /**
     * Evaluates the cooling soil start gate.
     *
     * Technical rule: delegate the shared source-priority and threshold rule to
     * the neutral planner.
     * Functional behavior: dry soil allows the zone, wet soil skips it, and no
     * active sensor source bypasses this start gate.
     * Domain rationale: cooling should avoid adding water where the root zone is
     * already moist, but missing telemetry should not block heat relief.
     *
     * @return array{allowed: bool, skipZone: bool, source: string, measuredSoil: ?float, reason: string, debugMessage: string}
     */
    public function evaluateSoilStartGate(
        ?float $zoneSoilMoisture,
        ?float $globalSoilMoisture,
        bool $useGlobalSoilMoisture,
        int $soilMinMoisture,
        int $zoneSoilMoistureVarId,
        int $globalSoilMoistureVarId
    ): array {
        return $this->zoneStartPlanner->evaluateSoilStartGate(
            $zoneSoilMoisture,
            $globalSoilMoisture,
            $useGlobalSoilMoisture,
            $soilMinMoisture,
            $zoneSoilMoistureVarId,
            $globalSoilMoistureVarId
        );
    }
}