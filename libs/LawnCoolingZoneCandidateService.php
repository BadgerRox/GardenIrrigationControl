<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class LawnCoolingZoneCandidateService
{
    public function __construct(
        private readonly ?DebugLoggerInterface $logger = null
    ) {
    }

    /**
     * Evaluates prepared cooling zones and keeps only zones that pass the start gates.
     *
     * Technical rule: consume already prepared cooling zones and matching soil
     * input payloads, then evaluate only the start-time soil gate.
     * Functional behavior: zones without UseCooling never reach this service,
     * wet zones are skipped locally, and missing soil sources bypass the gate.
     * Domain rationale: lawn cooling is a targeted heat-relief function; it
     * should avoid adding water to already moist root zones without blocking a
     * needed cooling run only because optional telemetry is absent.
     *
     * @param array<int, array<string, mixed>> $coolingZones
     * @param array<int, array{zoneLabel: string, zoneSoilValue: float|null, globalSoilValue: float|null, useGlobalSoil: bool, soilMinMoisture: int, zoneSoilVarId: int, globalSoilVarId: int}> $zoneInputs
     * @return array{allowed: bool, zones: array<int, array<string, mixed>>, skippedZones: array<int, array{zone: array<string, mixed>, reason: string, debugMessage: string}>, reason: string, debugMessage: string}
     */
    public function evaluateCandidates(
        LawnCoolingZoneStartPlanner $planner,
        array $coolingZones,
        array $zoneInputs,
        string $context
    ): array {
        // Technically: stop candidate creation when no active UseCooling zone exists.
        // Functional behavior: the cooling entrypoint ends cleanly without trying to open a valve.
        // Domain rationale: cooling is intentionally opt-in per zone; running a non-cooling zone would add unplanned water and distort the lawn-specific heat-relief intent.
        if ($coolingZones === []) {
            return [
                'allowed'      => false,
                'zones'        => [],
                'skippedZones' => [],
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_NO_ACTIVE_COOLING_ZONES,
                'debugMessage' => 'Zone check ended: no active cooling zone with UseCooling=true was found.',
            ];
        }

        $startableZones = [];
        $skippedZones = [];

        foreach ($coolingZones as $index => $zone) {
            $input = $zoneInputs[$index] ?? $this->buildMissingInputFallback($zone);
            $soilGate = $planner->evaluateSoilStartGate(
                $input['zoneSoilValue'],
                $input['globalSoilValue'],
                $input['useGlobalSoil'],
                $input['soilMinMoisture'],
                $input['zoneSoilVarId'],
                $input['globalSoilVarId']
            );

            $this->logger?->debug($context, sprintf('Zone %s: %s', $input['zoneLabel'], $soilGate['debugMessage']));

            if ($soilGate['allowed']) {
                $startableZones[] = $zone;
                continue;
            }

            $skippedZones[] = [
                'zone'         => $zone,
                'reason'       => $soilGate['reason'],
                'debugMessage' => $soilGate['debugMessage'],
            ];
        }

        // Technically: all candidate zones were filtered out by the start-only soil gate.
        // Functional behavior: the entrypoint has no valve to start and exits as a soft block.
        // Domain rationale: sufficiently moist root zones do not need heat-relief watering; forcing a run would risk unnecessary saturation after recent irrigation or rainfall.
        if ($startableZones === []) {
            return [
                'allowed'      => false,
                'zones'        => [],
                'skippedZones' => $skippedZones,
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_SOIL_START_BLOCKED,
                'debugMessage' => sprintf('Zone check blocked: all %d cooling zone(s) were skipped by the soil start check.', count($coolingZones)),
            ];
        }

        $this->logger?->debug(
            $context,
            sprintf('Zone check clear: %d of %d active cooling zone(s) are ready to start.', count($startableZones), count($coolingZones))
        );

        return [
            'allowed'      => true,
            'zones'        => $startableZones,
            'skippedZones' => $skippedZones,
            'reason'       => 'cooling_zones_ready',
            'debugMessage' => 'Active cooling zones were loaded, sorted, and evaluated by the soil start gate.',
        ];
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{zoneLabel: string, zoneSoilValue: float|null, globalSoilValue: float|null, useGlobalSoil: bool, soilMinMoisture: int, zoneSoilVarId: int, globalSoilVarId: int}
     */
    private function buildMissingInputFallback(array $zone): array
    {
        $name = isset($zone['Name']) && is_string($zone['Name']) && trim($zone['Name']) !== ''
            ? trim($zone['Name'])
            : 'unnamed';
        $sequence = isset($zone['Sequence']) && is_numeric($zone['Sequence']) ? (int) $zone['Sequence'] : -1;

        return [
            'zoneLabel'          => sprintf('%s (Seq %d)', $name, $sequence),
            'zoneSoilValue'      => null,
            'globalSoilValue'    => null,
            'useGlobalSoil'      => false,
            'soilMinMoisture'    => 0,
            'zoneSoilVarId'      => 0,
            'globalSoilVarId'    => 0,
        ];
    }
}