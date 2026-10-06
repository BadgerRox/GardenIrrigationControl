<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

/**
 * Evaluates the sequential pre-start gate chain for a zone without executing Symcon side effects.
 * The valve open action remains the caller's responsibility after receiving allowed=true.
 */
final class ZoneStartPipelineService
{
    public function __construct(
        private readonly ?DebugLoggerInterface $logger = null
    ) {
    }

    /**
     * Runs all zone pre-start gates and returns a decision envelope.
     * Does not open valves; the caller executes that side effect when allowed=true.
     *
     * @param array{
     *     zoneLabel: string,
     *     rainForecastEnabled: bool,
     *     forecast: array<string, mixed>,
     *     anchorWasStale: bool,
     *     refreshSucceeded: bool,
     *     storageMm: float|null,
     *     rainTodayMm: float|null,
     *     irrigationTodayMm: float|null,
     *     precipRate: float,
     *     irrigationMinRuntime: float,
     *     irrigationZoneMaxRuntime: int,
     *     runStartTimestamp: int|null,
     *     maxRuntimeSeconds: int,
     *     lastAutoIrrigationDate: string|null,
     *     irrigationInterval: int,
     *     today: string,
     *     zoneSoilValue: float|null,
     *     globalSoilValue: float|null,
     *     useGlobalSoil: bool,
     *     soilMinMoisture: int,
     *     zoneSoilVarId: int,
     *     globalSoilVarId: int
     * } $inputs
     * @return array{allowed: bool, globalStop: bool, reason: string, runtimeEffectiveMinutes: float|null}
     */
    public function evaluateGates(AutoIrrigationZoneStartPlanner $planner, array $inputs): array
    {
        $zoneLabel = $inputs['zoneLabel'];

        // ==========================================
        // GATE 1: Rain forecast
        // ==========================================
        $forecastResult = $planner->evaluateForecastGate(
            $inputs['rainForecastEnabled'],
            $inputs['forecast'],
            null,
            12,
            70,
            5.0
        );
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: rain forecast checked before zone start. %s', $zoneLabel, (string) $forecastResult['debugMessage']));

        if ($forecastResult['globalStop']) {
            return ['allowed' => false, 'globalStop' => true, 'reason' => 'forecast_match', 'runtimeEffectiveMinutes' => null];
        }

        // ==========================================
        // GATE 2: Water storage anchor freshness
        // ==========================================
        $consistencyResult = $planner->evaluateDataConsistencyGate($inputs['anchorWasStale'], $inputs['refreshSucceeded']);
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: persistent water-storage data freshness checked. %s', $zoneLabel, (string) $consistencyResult['debugMessage']));

        if (!$consistencyResult['allowed']) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $consistencyResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $consistencyResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        // ==========================================
        // GATE 3: Required irrigation amount
        // ==========================================
        $requiredResult = $planner->computeRequiredMm($inputs['storageMm'], $inputs['rainTodayMm'], $inputs['irrigationTodayMm']);
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: water demand calculated from storage, rain, and daily consumption. %s', $zoneLabel, (string) $requiredResult['debugMessage']));

        if (!$requiredResult['allowed'] || !is_float($requiredResult['requiredMm'])) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $requiredResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $requiredResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        // ==========================================
        // GATE 4: Runtime derivation
        // ==========================================
        $runtimeResult = $planner->deriveRuntime(
            $requiredResult['requiredMm'],
            $inputs['precipRate'],
            $inputs['irrigationMinRuntime'],
            $inputs['irrigationZoneMaxRuntime']
        );
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: target runtime derived from water demand and precipitation rate. %s', $zoneLabel, (string) $runtimeResult['debugMessage']));

        if (!$runtimeResult['allowed']) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $runtimeResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $runtimeResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        // Technical behavior: evaluate the remaining absolute daily budget after deriving the candidate zone runtime.
        // Functional behavior: prevent this zone from reaching the valve-open side effect when less than the configured minimum runtime remains.
        // Domain rationale: the final daily window must not create an unusably short irrigation cycle or consume time needed by a valid earlier decision.
        $remainingRuntimeResult = $planner->evaluateRemainingDailyRuntimeGate(
            $inputs['runStartTimestamp'],
            $inputs['maxRuntimeSeconds'],
            $inputs['irrigationMinRuntime']
        );
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: remaining runtime checked before zone start. %s', $zoneLabel, (string) $remainingRuntimeResult['debugMessage']));

        if (!$remainingRuntimeResult['allowed']) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $remainingRuntimeResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $remainingRuntimeResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        // ==========================================
        // GATE 5: Irrigation interval
        // ==========================================
        $intervalResult = $planner->evaluateIntervalGate(
            $inputs['lastAutoIrrigationDate'],
            $inputs['irrigationInterval'],
            $inputs['today'],
            (float) $runtimeResult['runtimeRawMinutes'],
            $inputs['irrigationZoneMaxRuntime']
        );
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: irrigation interval and edge-case override checked. %s', $zoneLabel, (string) $intervalResult['debugMessage']));

        if (!$intervalResult['allowed']) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $intervalResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $intervalResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        // ==========================================
        // GATE 6: Soil moisture start check
        // ==========================================
        $soilResult = $planner->evaluateSoilStartGate(
            $inputs['zoneSoilValue'],
            $inputs['globalSoilValue'],
            $inputs['useGlobalSoil'],
            $inputs['soilMinMoisture'],
            $inputs['zoneSoilVarId'],
            $inputs['globalSoilVarId']
        );
        $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s: soil moisture checked before zone start. %s', $zoneLabel, (string) $soilResult['debugMessage']));

        if (!$soilResult['allowed']) {
            $this->logger?->debug('AutoIrrigationDaily', sprintf('Zone %s is skipped (%s).', $zoneLabel, (string) $soilResult['reason']));

            return ['allowed' => false, 'globalStop' => false, 'reason' => (string) $soilResult['reason'], 'runtimeEffectiveMinutes' => null];
        }

        return [
            'allowed'                 => true,
            'globalStop'              => false,
            'reason'                  => 'zone_start_gates_passed',
            'runtimeEffectiveMinutes' => (float) $runtimeResult['runtimeEffectiveMinutes'],
        ];
    }

    /**
     * Runs the resume-reentry gate chain (same sequence as evaluateGates, no valve open).
     * Debug context is AutoIrrigationResumeReentry; return shape carries a debugMessage instead of runtimeEffectiveMinutes.
     *
     * @param array{
     *     zoneLabel: string,
     *     rainForecastEnabled: bool,
     *     forecast: array<string, mixed>,
     *     anchorWasStale: bool,
     *     refreshSucceeded: bool,
     *     storageMm: float|null,
     *     rainTodayMm: float|null,
     *     irrigationTodayMm: float|null,
     *     precipRate: float,
     *     irrigationMinRuntime: float,
     *     irrigationZoneMaxRuntime: int,
     *     runStartTimestamp: int|null,
     *     maxRuntimeSeconds: int,
     *     lastAutoIrrigationDate: string|null,
     *     irrigationInterval: int,
     *     today: string,
     *     zoneSoilValue: float|null,
     *     globalSoilValue: float|null,
     *     useGlobalSoil: bool,
     *     soilMinMoisture: int,
     *     zoneSoilVarId: int,
     *     globalSoilVarId: int
     * } $inputs
     * @return array{allowed: bool, globalStop: bool, reason: string, debugMessage: string}
     */
    public function evaluateResumeGates(AutoIrrigationZoneStartPlanner $planner, array $inputs): array
    {
        $forecastResult = $planner->evaluateForecastGate(
            $inputs['rainForecastEnabled'],
            $inputs['forecast'],
            null,
            12,
            70,
            5.0
        );
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Rain forecast checked before resume re-entry: ' . (string) $forecastResult['debugMessage']);

        if ($forecastResult['globalStop']) {
            return ['allowed' => false, 'globalStop' => true, 'reason' => 'Resume re-entry stopped: forecast gate triggered a global stop.', 'debugMessage' => (string) $forecastResult['debugMessage']];
        }

        $consistencyResult = $planner->evaluateDataConsistencyGate($inputs['anchorWasStale'], $inputs['refreshSucceeded']);
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Persistent water-storage data checked for resume re-entry: ' . (string) $consistencyResult['debugMessage']);

        if (!$consistencyResult['allowed']) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: data consistency failed; zone ends locally.', 'debugMessage' => (string) $consistencyResult['debugMessage']];
        }

        $requiredResult = $planner->computeRequiredMm($inputs['storageMm'], $inputs['rainTodayMm'], $inputs['irrigationTodayMm']);
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Water demand for resume re-entry calculated from storage, rain, and daily consumption: ' . (string) $requiredResult['debugMessage']);

        if (!$requiredResult['allowed'] || !is_float($requiredResult['requiredMm'])) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: water demand cannot be derived; zone ends locally.', 'debugMessage' => (string) $requiredResult['debugMessage']];
        }

        $runtimeResult = $planner->deriveRuntime(
            $requiredResult['requiredMm'],
            $inputs['precipRate'],
            $inputs['irrigationMinRuntime'],
            $inputs['irrigationZoneMaxRuntime']
        );
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Target runtime derived for resume re-entry: ' . (string) $runtimeResult['debugMessage']);

        if (!$runtimeResult['allowed']) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: runtime gate blocks the zone.', 'debugMessage' => (string) $runtimeResult['debugMessage']];
        }

        // Technical behavior: re-evaluate the remaining daily budget during resume re-entry using the same minimum-runtime rule as a fresh zone start.
        // Functional behavior: prevent a paused zone from reopening when the daily cutoff leaves insufficient time for a valid continuation.
        // Domain rationale: pause time counts toward the daily budget, so resuming without this guard could create a truncated final cycle after weather interruptions.
        $remainingRuntimeResult = $planner->evaluateRemainingDailyRuntimeGate(
            $inputs['runStartTimestamp'],
            $inputs['maxRuntimeSeconds'],
            $inputs['irrigationMinRuntime']
        );
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Remaining runtime checked before resume re-entry: ' . (string) $remainingRuntimeResult['debugMessage']);

        if (!$remainingRuntimeResult['allowed']) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: remaining runtime is below the minimum runtime.', 'debugMessage' => (string) $remainingRuntimeResult['debugMessage']];
        }

        $intervalResult = $planner->evaluateIntervalGate(
            $inputs['lastAutoIrrigationDate'],
            $inputs['irrigationInterval'],
            $inputs['today'],
            (float) $runtimeResult['runtimeRawMinutes'],
            $inputs['irrigationZoneMaxRuntime']
        );
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Irrigation interval checked for resume re-entry: ' . (string) $intervalResult['debugMessage']);

        if (!$intervalResult['allowed']) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: interval gate blocks the zone.', 'debugMessage' => (string) $intervalResult['debugMessage']];
        }

        $soilResult = $planner->evaluateSoilStartGate(
            $inputs['zoneSoilValue'],
            $inputs['globalSoilValue'],
            $inputs['useGlobalSoil'],
            $inputs['soilMinMoisture'],
            $inputs['zoneSoilVarId'],
            $inputs['globalSoilVarId']
        );
        $this->logger?->debug('AutoIrrigationResumeReentry', 'Soil moisture checked before resume re-entry: ' . (string) $soilResult['debugMessage']);

        if (!$soilResult['allowed']) {
            return ['allowed' => false, 'globalStop' => false, 'reason' => 'Resume re-entry: soil start gate blocks the zone.', 'debugMessage' => (string) $soilResult['debugMessage']];
        }

        // Technically: return a uniform success envelope for the caller-side outcome router.
        // Functional behavior: resumed execution may continue in the current zone without ambiguous fallback paths.
        // Domain rationale: explicit success signaling keeps pause recovery deterministic and operator-visible.
        return ['allowed' => true, 'globalStop' => false, 'reason' => 'Resume re-entry succeeded.', 'debugMessage' => 'Resume re-entry completed successfully.'];
    }
}
