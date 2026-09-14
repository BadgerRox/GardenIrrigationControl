<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class ZoneStartPlanner
{
    /**
     * Loads, filters and sorts zones for a sequential zone-based run.
     *
     * Technical rule: keep only active zones with a numeric Sequence and apply
     * the optional domain-specific filter before sorting by Sequence, ValveVarID
     * and original input order.
     * Functional behavior: callers receive a deterministic list of candidate
     * zones without having to duplicate zone-tree normalization.
     * Domain rationale: stable ordering prevents accidental watering-order
     * changes when two zones share the same configured sequence, while each
     * domain can still restrict the candidate set to its own purpose.
     *
     * @param array<int, array<string, mixed>> $zonesTree
     * @param callable(array<string, mixed>): bool|null $additionalFilter
     * @return array<int, array<string, mixed>>
     */
    public function prepareActiveZones(array $zonesTree, ?callable $additionalFilter = null): array
    {
        $preparedZones = [];

        foreach ($zonesTree as $index => $zone) {
            if (!isset($zone['Active']) || (int) $zone['Active'] !== 1) {
                continue;
            }

            if (!isset($zone['Sequence']) || !is_numeric($zone['Sequence'])) {
                continue;
            }

            if ($additionalFilter !== null && !$additionalFilter($zone)) {
                continue;
            }

            $zone['Sequence'] = (int) $zone['Sequence'];
            $zone['__zoneStartOriginalIndex'] = (int) $index;
            $preparedZones[] = $zone;
        }

        usort(
            $preparedZones,
            static function (array $left, array $right): int
            {
                $sequenceComparison = $left['Sequence'] <=> $right['Sequence'];
                if ($sequenceComparison !== 0) {
                    return $sequenceComparison;
                }

                $valveComparison = (int) ($left['ValveVarID'] ?? 0) <=> (int) ($right['ValveVarID'] ?? 0);
                if ($valveComparison !== 0) {
                    return $valveComparison;
                }

                return (int) $left['__zoneStartOriginalIndex'] <=> (int) $right['__zoneStartOriginalIndex'];
            }
        );

        foreach ($preparedZones as &$zone) {
            unset($zone['__zoneStartOriginalIndex']);
        }
        unset($zone);

        return $preparedZones;
    }

    /**
     * Evaluates the soil start gate with explicit source priority.
     *
     * Technical rule: prefer the zone sensor when present, otherwise use the
     * global sensor if the zone opted in and a global sensor exists.
     * Functional behavior: no active source means the gate is bypassed, while a
     * measured value at or above the threshold skips the zone.
     * Domain rationale: a local root-zone reading is the best available signal;
     * a global fallback is useful only when explicitly allowed, and missing
     * telemetry must not suppress an otherwise valid run.
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
        $hasZoneSource = $zoneSoilMoistureVarId > 0 && $zoneSoilMoisture !== null;
        $hasGlobalSource = $useGlobalSoilMoisture && $globalSoilMoistureVarId > 0 && $globalSoilMoisture !== null;

        if (!$hasZoneSource && !$hasGlobalSource) {
            return [
                'allowed'      => true,
                'skipZone'     => false,
                'source'       => 'none',
                'measuredSoil' => null,
                'reason'       => 'soil_source_missing',
                'debugMessage' => 'Soil start check skipped: no active soil moisture source is available.',
            ];
        }

        $source = $hasZoneSource ? 'zone' : 'global';
        $measuredSoil = $hasZoneSource ? (float) $zoneSoilMoisture : (float) $globalSoilMoisture;

        if ($measuredSoil >= (float) $soilMinMoisture) {
            return [
                'allowed'      => false,
                'skipZone'     => true,
                'source'       => $source,
                'measuredSoil' => $measuredSoil,
                'reason'       => 'soil_above_threshold_at_start',
                'debugMessage' => sprintf('Soil start check blocked: source=%s, reading=%.2f%%, limit=%d%%.', $source, $measuredSoil, $soilMinMoisture),
            ];
        }

        return [
            'allowed'      => true,
            'skipZone'     => false,
            'source'       => $source,
            'measuredSoil' => $measuredSoil,
            'reason'       => 'soil_below_threshold',
            'debugMessage' => sprintf('Soil start check clear: source=%s, reading=%.2f%%, limit=%d%%.', $source, $measuredSoil, $soilMinMoisture),
        ];
    }
}