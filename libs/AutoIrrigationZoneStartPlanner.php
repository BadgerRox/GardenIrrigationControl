<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class AutoIrrigationZoneStartPlanner
{
    public function __construct(private readonly ZoneStartPlanner $zoneStartPlanner = new ZoneStartPlanner())
    {
    }

    /**
     * Returns the canonical runtime-loop check order.
     *
     * @return array<int, string>
     */
    public function getRuntimeLoopCheckOrder(): array
    {
        return [
            'recheck_global_preconditions',
            'enforce_daily_cutoff',
            'wind_pause_gate',
            'rain_pause_gate',
            'soil_runtime_gate_if_mode_1',
            'target_reached_gate',
        ];
    }

    /**
     * Evaluates the hard start guards for the daily auto irrigation run.
     *
     * @return array{allowed: bool, terminalState: string, reason: string, debugMessage: string}
     */
    public function evaluateStartPrecheck(
        bool $systemAutoEnable,
        bool $systemMaintenanceEnable,
        bool $moduleConfigurationValid,
        bool $hardStartBlockerActive = false,
        string $hardStartBlockerReason = '',
        string $hardStartBlockerDebugMessage = ''
    ): array {
        if (!$moduleConfigurationValid) {
            return [
                'allowed'       => false,
                'terminalState' => AutoIrrigationContracts::RUN_STATE_STOPPED,
                'reason'        => 'Required configuration is invalid.',
                'debugMessage'  => 'Precheck aborted: required configuration is invalid, so no daily run will start.',
            ];
        }

        if (!$systemAutoEnable) {
            return [
                'allowed'       => false,
                'terminalState' => AutoIrrigationContracts::RUN_STATE_STOPPED,
                'reason'        => 'Automatic mode is disabled.',
                'debugMessage'  => 'Precheck aborted: automatic mode is disabled.',
            ];
        }

        if ($systemMaintenanceEnable) {
            return [
                'allowed'       => false,
                'terminalState' => AutoIrrigationContracts::RUN_STATE_STOPPED,
                'reason'        => 'Maintenance mode is active.',
                'debugMessage'  => 'Precheck aborted: maintenance mode is active.',
            ];
        }

        if ($hardStartBlockerActive) {
            return [
                'allowed'       => false,
                'terminalState' => AutoIrrigationContracts::RUN_STATE_STOPPED,
                'reason'        => $hardStartBlockerReason !== '' ? $hardStartBlockerReason : 'Valve failure blocks the daily start.',
                'debugMessage'  => $hardStartBlockerDebugMessage !== ''
                    ? $hardStartBlockerDebugMessage
                    : 'Precheck aborted: a hard valve start blocker is active.',
            ];
        }

        return [
            'allowed'       => true,
            'terminalState' => AutoIrrigationContracts::RUN_STATE_PRECHECK,
            'reason'        => 'Precheck succeeded.',
            'debugMessage'  => 'Precheck succeeded: automatic mode is active, maintenance mode is off, and configuration is valid.',
        ];
    }

    /**
     * Loads, filters and sorts the active zones for the daily run.
     *
     * @param array<int, array<string, mixed>> $zonesTree
     * @return array<int, array<string, mixed>>
     */
    public function prepareActiveZones(array $zonesTree): array
    {
        return $this->zoneStartPlanner->prepareActiveZones($zonesTree);
    }

    /**
     * Evaluates the forecast gate before a zone is started.
     *
     * Technical rule: use the resolved forecast horizon and threshold values to
     * determine whether the next rain window blocks irrigation.
     * Functional behavior: a valid match stops the run, while a forecast error
     * fails open so irrigation can continue.
     * Domain rationale: weather forecasts are advisory; a broken API must not
     * suppress watering, but a strong rain signal should prevent avoidable waste.
     *
     * @param array<string, mixed> $forecast
     * @return array{allowed: bool, globalStop: bool, failOpen: bool, matched: bool, resolvedForecastHours: int, probabilityThresholdPercent: int, rainAmountThresholdMm: float, reason: string, debugMessage: string}
     */
    public function evaluateForecastGate(
        bool $rainForecastEnabled,
        array $forecast,
        ?int $requestedForecastHours = null,
        int $fallbackForecastHours = 12,
        int $probabilityThresholdPercent = 70,
        float $rainAmountThresholdMm = 5.0
    ): array {
        $resolvedForecastHours = $requestedForecastHours !== null && $requestedForecastHours > 0
            ? $requestedForecastHours
            : $fallbackForecastHours;

        if (!$rainForecastEnabled) {
            return [
                'allowed'                     => true,
                'globalStop'                  => false,
                'failOpen'                    => false,
                'matched'                     => false,
                'resolvedForecastHours'       => $resolvedForecastHours,
                'probabilityThresholdPercent' => $probabilityThresholdPercent,
                'rainAmountThresholdMm'       => round($rainAmountThresholdMm, 4, PHP_ROUND_HALF_UP),
                'reason'                      => 'forecast_disabled',
                'debugMessage'                => 'Forecast check skipped: rain forecast is disabled.',
            ];
        }

        if ($forecast === [] || !isset($forecast['rainSum'], $forecast['maxProb']) || !is_numeric($forecast['rainSum']) || !is_numeric($forecast['maxProb'])) {
            return [
                'allowed'                     => true,
                'globalStop'                  => false,
                'failOpen'                    => true,
                'matched'                     => false,
                'resolvedForecastHours'       => $resolvedForecastHours,
                'probabilityThresholdPercent' => $probabilityThresholdPercent,
                'rainAmountThresholdMm'       => round($rainAmountThresholdMm, 4, PHP_ROUND_HALF_UP),
                'reason'                      => 'forecast_error_fail_open',
                'debugMessage'                => 'Forecast check is fail-open: no usable forecast is available; the run continues.',
            ];
        }

        $rainSum = round((float) $forecast['rainSum'], 4, PHP_ROUND_HALF_UP);
        $maxProb = (float) $forecast['maxProb'];
        $thresholdRain = round($rainAmountThresholdMm, 4, PHP_ROUND_HALF_UP);

        $matched = $maxProb >= $probabilityThresholdPercent && $rainSum >= $thresholdRain;

        return [
            'allowed'                     => !$matched,
            'globalStop'                  => $matched,
            'failOpen'                    => false,
            'matched'                     => $matched,
            'resolvedForecastHours'       => $resolvedForecastHours,
            'probabilityThresholdPercent' => $probabilityThresholdPercent,
            'rainAmountThresholdMm'       => $thresholdRain,
            'reason'                      => $matched ? 'forecast_match' : 'forecast_clear',
            'debugMessage'                => $matched
                ? sprintf('Forecast check triggered: %.2f%% rain probability with %.4f mm in the next %d hours.', $maxProb, $rainSum, $resolvedForecastHours)
                : sprintf('Forecast check clear: %.2f%% rain probability with %.4f mm is below the thresholds.', $maxProb, $rainSum),
        ];
    }

    /**
     * Evaluates the daily cutoff against the fixed run start anchor.
     *
     * Technical rule: compare the current timestamp against run_start_timestamp + configured runtime duration.
     * Functional behavior: once the cutoff is exceeded, the run must stop globally and deterministically.
     * Domain rationale: a calendar-day irrigation window must not overrun into an uncontrolled extra runtime.
     *
     * @return array{allowed: bool, globalStop: bool, cutoffReached: bool, runStartTimestamp: ?int, cutoffTimestamp: ?int, nowTimestamp: int, reason: string, debugMessage: string}
     */
    public function evaluateDailyCutoffGate(?int $runStartTimestamp, int $maxRuntimeSeconds, ?int $nowTimestamp = null): array
    {
        $nowTimestamp = $nowTimestamp ?? time();

        if ($runStartTimestamp === null || $runStartTimestamp <= 0 || $maxRuntimeSeconds <= 0) {
            return [
                'allowed'           => true,
                'globalStop'        => false,
                'cutoffReached'     => false,
                'runStartTimestamp' => $runStartTimestamp,
                'cutoffTimestamp'   => null,
                'nowTimestamp'      => $nowTimestamp,
                'reason'            => 'cutoff_not_applicable',
                'debugMessage'      => 'Daily cutoff skipped: run start or runtime is invalid.',
            ];
        }

        $cutoffTimestamp = $runStartTimestamp + $maxRuntimeSeconds;
        $cutoffReached = $nowTimestamp > $cutoffTimestamp;

        if (!$cutoffReached) {
            return [
                'allowed'           => true,
                'globalStop'        => false,
                'cutoffReached'     => false,
                'runStartTimestamp' => $runStartTimestamp,
                'cutoffTimestamp'   => $cutoffTimestamp,
                'nowTimestamp'      => $nowTimestamp,
                'reason'            => 'cutoff_not_reached',
                'debugMessage'      => sprintf('Daily cutoff clear: now=%d is before cutoff=%d.', $nowTimestamp, $cutoffTimestamp),
            ];
        }

        return [
            'allowed'           => false,
            'globalStop'        => true,
            'cutoffReached'     => true,
            'runStartTimestamp' => $runStartTimestamp,
            'cutoffTimestamp'   => $cutoffTimestamp,
            'nowTimestamp'      => $nowTimestamp,
            'reason'            => 'cutoff_reached',
            'debugMessage'      => sprintf('Daily cutoff blocked: now=%d exceeds cutoff=%d.', $nowTimestamp, $cutoffTimestamp),
        ];
    }

    /**
     * Evaluates whether the remaining daily budget can accommodate the configured minimum zone runtime.
     *
     * Technical behavior: subtract elapsed wall-clock time from the absolute daily runtime budget and compare the remainder with the minimum runtime.
     * Functional behavior: reject a new zone before valve opening when the remaining budget cannot provide one valid minimum-runtime cycle.
     * Domain rationale: a partial final cycle wastes water and creates an incomplete irrigation event; allowing it would also make zone order dependent on timing.
     *
     * @return array{allowed: bool, skipZone: bool, remainingRuntimeSeconds: ?int, minimumRuntimeSeconds: float, reason: string, debugMessage: string}
     */
    public function evaluateRemainingDailyRuntimeGate(
        ?int $runStartTimestamp,
        int $maxRuntimeSeconds,
        float $minimumRuntimeMinutes,
        ?int $nowTimestamp = null
    ): array {
        $nowTimestamp = $nowTimestamp ?? time();
        $minimumRuntimeSeconds = max(0.0, $minimumRuntimeMinutes * 60.0);

        if ($runStartTimestamp === null || $runStartTimestamp <= 0 || $maxRuntimeSeconds <= 0) {
            return [
                'allowed'                  => true,
                'skipZone'                 => false,
                'remainingRuntimeSeconds'  => null,
                'minimumRuntimeSeconds'    => $minimumRuntimeSeconds,
                'reason'                   => 'remaining_runtime_not_applicable',
                'debugMessage'             => 'Remaining-runtime gate skipped: run start or maximum runtime is invalid.',
            ];
        }

        $elapsedSeconds = max(0, $nowTimestamp - $runStartTimestamp);
        $remainingRuntimeSeconds = max(0, $maxRuntimeSeconds - $elapsedSeconds);

        // Technical behavior: apply a small numeric tolerance at the seconds-to-minutes boundary.
        // Functional behavior: permit a zone when the remaining budget is equal to its minimum runtime despite floating-point conversion noise.
        // Domain rationale: an exact boundary must not be rejected because of representation rounding, while any genuinely shorter window remains blocked.
        if ((float) $remainingRuntimeSeconds + 0.0001 >= $minimumRuntimeSeconds) {
            return [
                'allowed'                  => true,
                'skipZone'                 => false,
                'remainingRuntimeSeconds'  => $remainingRuntimeSeconds,
                'minimumRuntimeSeconds'    => $minimumRuntimeSeconds,
                'reason'                   => 'remaining_runtime_sufficient',
                'debugMessage'             => sprintf('Remaining-runtime gate clear: remaining=%d s, minimum runtime=%.1f s.', $remainingRuntimeSeconds, $minimumRuntimeSeconds),
            ];
        }

        return [
            'allowed'                  => false,
            'skipZone'                 => true,
            'remainingRuntimeSeconds'  => $remainingRuntimeSeconds,
            'minimumRuntimeSeconds'    => $minimumRuntimeSeconds,
            'reason'                   => 'remaining_daily_runtime_below_minimum',
            'debugMessage'             => sprintf('Remaining-runtime gate blocked: remaining=%d s, minimum runtime=%.1f s.', $remainingRuntimeSeconds, $minimumRuntimeSeconds),
        ];
    }

    /**
     * Evaluates whether the per-zone water-balance data is still fresh enough.
     *
     * Technical rule: the caller decides whether the zone data is stale and
     * whether a refresh succeeded.
     * Functional behavior: stale data triggers a refresh attempt; refresh
     * failure skips only the current zone.
     * Domain rationale: storage and runtime calculations are only trustworthy
     * when the anchor data is current, but a refresh failure must not abort the whole day.
     *
     * @return array{stale: bool, allowed: bool, skipZone: bool, reason: string, debugMessage: string}
     */
    public function evaluateDataConsistencyGate(bool $stale, bool $refreshSucceeded): array
    {
        if (!$stale) {
            return [
                'stale'        => false,
                'allowed'      => true,
                'skipZone'     => false,
                'reason'       => 'data_consistent',
                'debugMessage' => 'Data consistency check clear: stored balance data is current.',
            ];
        }

        if ($refreshSucceeded) {
            return [
                'stale'        => true,
                'allowed'      => true,
                'skipZone'     => false,
                'reason'       => 'refresh_success',
                'debugMessage' => 'Data consistency check refreshed stale data successfully; the zone can continue.',
            ];
        }

        return [
            'stale'        => true,
            'allowed'      => false,
            'skipZone'     => true,
            'reason'       => 'data_consistency_refresh_failed',
            'debugMessage' => 'Data consistency check: refresh failed, so only the current zone is skipped.',
        ];
    }

    /**
     * Validates the storage gate and computes the required irrigation depth.
     *
     * Technical rule: storage must be a numeric value below zero and the water
     * need is derived from storage plus daily rain and irrigation.
     * Functional behavior: missing inputs skip the zone, while a valid negative
     * storage value produces a rounded required mm result.
     * Domain rationale: only a negative storage means the root zone has a real
     * deficit; positive or unknown storage must not trigger watering.
     *
     * @return array{allowed: bool, skipZone: bool, requiredMm: ?float, reason: string, debugMessage: string}
     */
    public function computeRequiredMm(?float $storageMm, ?float $rainTodayMm, ?float $irrigationTodayMm): array
    {
        if ($storageMm === null || $rainTodayMm === null || $irrigationTodayMm === null) {
            return [
                'allowed'      => false,
                'skipZone'     => true,
                'requiredMm'   => null,
                'reason'       => 'required_mm_input_missing',
                'debugMessage' => 'Storage check skipped: at least one input is missing, so the zone is not evaluated.',
            ];
        }

        if ($storageMm >= 0.0) {
            return [
                'allowed'      => false,
                'skipZone'     => true,
                'requiredMm'   => null,
                'reason'       => 'storage_non_negative',
                'debugMessage' => sprintf('Storage check blocked: storage is %.4f mm and is not negative.', $storageMm),
            ];
        }

        $requiredMm = round($storageMm + $rainTodayMm + $irrigationTodayMm, 4, PHP_ROUND_HALF_UP);
        $requiredMm = $requiredMm * -1.0; // Swap the signs, as we need a potential demand to be a positive value.

        return [
            'allowed'      => true,
            'skipZone'     => false,
            'requiredMm'   => $requiredMm,
            'reason'       => 'storage_negative',
            'debugMessage' => sprintf('Required water calculated: storage=%.4f mm, rain=%.4f mm, irrigation=%.4f mm, required=%.4f mm.', $storageMm, $rainTodayMm, $irrigationTodayMm, $requiredMm),
        ];
    }

    /**
     * Derives the raw and effective runtime for a zone.
     *
     * Technical rule: runtime is calculated from the required mm and the zone's
     * precipitation rate, then capped at the configured zone maximum.
     * Functional behavior: runtime below the minimum skips the zone, runtime
     * above the maximum is capped but not skipped.
     * Domain rationale: tiny deficits are not worth a valve cycle, while long
     * runtimes must be limited to avoid over-irrigation and mechanical stress.
     *
     * @return array{allowed: bool, skipZone: bool, runtimeRawMinutes: float, runtimeEffectiveMinutes: float, runtimeCapped: bool, reason: string, debugMessage: string}
     */
    public function deriveRuntime(
        float $requiredMm,
        float $sprinklerPrecipitationRate,
        float $minimumRuntimeMinutes,
        int $zoneMaxRuntimeMinutes
    ): array {
        if ($sprinklerPrecipitationRate <= 0.0) {
            return [
                'allowed'                 => false,
                'skipZone'                => true,
                'runtimeRawMinutes'       => 0.0,
                'runtimeEffectiveMinutes' => 0.0,
                'runtimeCapped'           => false,
                'reason'                  => 'invalid_precipitation_rate',
                'debugMessage'            => 'Runtime calculation skipped: precipitation rate is invalid or zero.',
            ];
        }

        $runtimeRawMinutes = round($requiredMm / $sprinklerPrecipitationRate, 4, PHP_ROUND_HALF_UP);
        if ($runtimeRawMinutes < $minimumRuntimeMinutes) {
            return [
                'allowed'                 => false,
                'skipZone'                => true,
                'runtimeRawMinutes'       => $runtimeRawMinutes,
                'runtimeEffectiveMinutes' => $runtimeRawMinutes,
                'runtimeCapped'           => false,
                'reason'                  => 'runtime_below_minimum',
                'debugMessage'            => sprintf('Runtime too short: %.4f min is below the minimum of %.4f min.', $runtimeRawMinutes, $minimumRuntimeMinutes),
            ];
        }

        $runtimeEffectiveMinutes = min($runtimeRawMinutes, (float) $zoneMaxRuntimeMinutes);
        $runtimeCapped = $runtimeEffectiveMinutes < $runtimeRawMinutes;

        return [
            'allowed'                 => true,
            'skipZone'                => false,
            'runtimeRawMinutes'       => $runtimeRawMinutes,
            'runtimeEffectiveMinutes' => $runtimeEffectiveMinutes,
            'runtimeCapped'           => $runtimeCapped,
            'reason'                  => $runtimeCapped ? 'runtime_capped_to_zone_maximum' : 'runtime_within_zone_limit',
            'debugMessage'            => $runtimeCapped
                ? sprintf('Runtime capped: raw=%.4f min, effective=%.4f min, zone limit=%d min.', $runtimeRawMinutes, $runtimeEffectiveMinutes, $zoneMaxRuntimeMinutes)
                : sprintf('Runtime allowed: raw=%.4f min, effective=%.4f min.', $runtimeRawMinutes, $runtimeEffectiveMinutes),
        ];
    }

    /**
     * Evaluates the stop signal checkpoint used before and during zone processing.
     *
     * Technical rule: a pending stop request is represented as a hard stop
     * signal and must be checked at every checkpoint.
     * Functional behavior: when the signal is set, the orchestrator must stop
     * deterministically instead of continuing to the next zone.
     * Domain rationale: a user or safety transition may request an immediate
     * halt, and the controller must honor that without waiting for the current
     * run to complete naturally.
     *
     * @return array{allowed: bool, globalStop: bool, reason: string, debugMessage: string}
     */
    public function evaluateStopSignalCheckpoint(bool $stopRequested, string $checkpointLabel = 'zone_checkpoint'): array
    {
        if (!$stopRequested) {
            return [
                'allowed'      => true,
                'globalStop'   => false,
                'reason'       => 'stop_signal_clear',
                'debugMessage' => 'Stop check clear: no stop signal is active.',
            ];
        }

        return [
            'allowed'      => false,
            'globalStop'   => true,
            'reason'       => 'stop_signal_requested',
            'debugMessage' => sprintf('Stop check triggered (%s): a stop signal is active and the run must end.', $checkpointLabel),
        ];
    }

    /**
     * Evaluates the irrigation interval gate and its edge override.
     *
     * Technical rule: compare the last irrigation date with the configured
     * interval and optionally override a not-due result when the raw runtime
     * would otherwise exceed the zone maximum.
     * Functional behavior: zones that are not yet due are skipped unless the
     * edge override applies, in which case the run is allowed with a capped runtime.
     * Domain rationale: interval rules prevent unnecessary valve cycles, but a
     * large deficit should not be lost just because the interval has not fully elapsed.
     *
     * @return array{allowed: bool, skipZone: bool, edgeOverrideUsed: bool, intervalDue: bool, reason: string, debugMessage: string}
     */
    public function evaluateIntervalGate(
        ?string $lastAutoIrrigationDate,
        int $irrigationIntervalDays,
        string $todayDate,
        float $runtimeRawMinutes,
        int $zoneMaxRuntimeMinutes
    ): array {
        $today = new \DateTimeImmutable($todayDate);

        if ($irrigationIntervalDays < 1) {
            return [
                'allowed'          => true,
                'skipZone'         => false,
                'edgeOverrideUsed' => false,
                'intervalDue'      => true,
                'reason'           => 'interval_not_applicable',
                'debugMessage'     => 'Interval check skipped: the interval is below 1 and is not applied.',
            ];
        }

        if ($lastAutoIrrigationDate === null || trim($lastAutoIrrigationDate) === '') {
            return [
                'allowed'          => true,
                'skipZone'         => false,
                'edgeOverrideUsed' => false,
                'intervalDue'      => true,
                'reason'           => 'interval_first_run',
                'debugMessage'     => 'Interval check clear: this zone has no previous irrigation date.',
            ];
        }

        $lastWateringDay = new \DateTimeImmutable($lastAutoIrrigationDate);
        $nextAllowedDay = $lastWateringDay->modify('+' . $irrigationIntervalDays . ' days');
        $intervalDue = $nextAllowedDay <= $today;

        if ($intervalDue) {
            return [
                'allowed'          => true,
                'skipZone'         => false,
                'edgeOverrideUsed' => false,
                'intervalDue'      => true,
                'reason'           => 'interval_due',
                'debugMessage'     => sprintf('Interval check clear: next allowed day %s has been reached or passed.', $nextAllowedDay->format('Y-m-d')),
            ];
        }

        if ($runtimeRawMinutes > (float) $zoneMaxRuntimeMinutes) {
            return [
                'allowed'          => true,
                'skipZone'         => false,
                'edgeOverrideUsed' => true,
                'intervalDue'      => false,
                'reason'           => 'interval_not_due_edge_override',
                'debugMessage'     => sprintf('Interval check override: although next allowed day %s has not been reached, the zone is allowed because raw runtime %.4f min exceeds the zone limit of %d min.', $nextAllowedDay->format('Y-m-d'), $runtimeRawMinutes, $zoneMaxRuntimeMinutes),
            ];
        }

        return [
            'allowed'          => false,
            'skipZone'         => true,
            'edgeOverrideUsed' => false,
            'intervalDue'      => false,
            'reason'           => 'interval_not_due',
            'debugMessage'     => sprintf('Interval check blocked: next allowed day is %s, today is %s.', $nextAllowedDay->format('Y-m-d'), $today->format('Y-m-d')),
        ];
    }

    /**
     * Evaluates the soil start gate with explicit source priority.
     *
     * Technical rule: prefer the zone sensor when present, otherwise use the
     * global sensor if the zone opted in and a global sensor exists.
     * Functional behavior: no active source means a bypass, while a measured
     * value at or above the threshold skips the zone.
     * Domain rationale: a zone-specific soil reading is more precise, but a
     * global fallback is still better than blocking irrigation without a usable source.
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

    /**
     * Evaluates the soil runtime gate for mode 1 with a confirmation window.
     *
     * Technical rule: the gate is only active in mode 1 and requires both
     * a current value above threshold and a stable 60-second confirmation.
     * Functional behavior: without confirmation, runtime continues; with
     * confirmation, only the current zone is stopped.
     * Domain rationale: prevents false stops from noisy single spikes while
     * still ending watering when soil is sustainably wet enough.
     *
     * @return array{allowed: bool, skipZone: bool, source: string, measuredSoil: ?float, reason: string, debugMessage: string}
     */
    public function evaluateSoilRuntimeGate(
        int $soilMoistureMode,
        string $source,
        ?float $measuredSoil,
        int $soilMinMoisture,
        bool $confirmWindowStable,
        int $sampleCount,
        int $confirmWindowSeconds = 60
    ): array {
        if ($soilMoistureMode !== 1) {
            return [
                'allowed'      => true,
                'skipZone'     => false,
                'source'       => $source,
                'measuredSoil' => $measuredSoil,
                'reason'       => 'soil_runtime_mode_disabled',
                'debugMessage' => 'Soil runtime check skipped: SoilMoistureMode is not 1.',
            ];
        }

        if ($source === 'none' || $measuredSoil === null) {
            return [
                'allowed'      => true,
                'skipZone'     => false,
                'source'       => $source,
                'measuredSoil' => $measuredSoil,
                'reason'       => 'soil_runtime_source_missing',
                'debugMessage' => 'Soil runtime check skipped: no active soil moisture source is available.',
            ];
        }

        if ($measuredSoil < (float) $soilMinMoisture) {
            return [
                'allowed'      => true,
                'skipZone'     => false,
                'source'       => $source,
                'measuredSoil' => $measuredSoil,
                'reason'       => 'soil_runtime_below_threshold',
                'debugMessage' => sprintf('Soil runtime check clear: source=%s, reading=%.2f%% is below limit=%d%%.', $source, $measuredSoil, $soilMinMoisture),
            ];
        }

        if (!$confirmWindowStable) {
            return [
                'allowed'      => true,
                'skipZone'     => false,
                'source'       => $source,
                'measuredSoil' => $measuredSoil,
                'reason'       => 'soil_runtime_confirm_window_pending',
                'debugMessage' => sprintf('Soil runtime check waiting: source=%s, reading=%.2f%% >= limit=%d%%, but the %ds confirmation window is not stable yet (samples=%d).', $source, $measuredSoil, $soilMinMoisture, $confirmWindowSeconds, $sampleCount),
            ];
        }

        return [
            'allowed'      => false,
            'skipZone'     => true,
            'source'       => $source,
            'measuredSoil' => $measuredSoil,
            'reason'       => 'soil_runtime_above_threshold_confirmed',
            'debugMessage' => sprintf('Soil runtime check blocked: source=%s, reading=%.2f%% >= limit=%d%% and the %ds confirmation window is stable (samples=%d).', $source, $measuredSoil, $soilMinMoisture, $confirmWindowSeconds, $sampleCount),
        ];
    }

    /**
     * Evaluates whether the effective target runtime of a zone was reached.
     *
     * Technical rule: compare watering elapsed minutes against the effective
     * runtime minutes that already include prior capping decisions.
     * Functional behavior: missing runtime context keeps the gate pass-through,
     * while reached target triggers a zone-local stop.
     * Domain rationale: irrigation must stop exactly at effective watering time;
     * non-watering pause periods must not count toward this target.
     *
     * @return array{allowed: bool, skipZone: bool, targetReached: bool, elapsedWateringMinutes: float, runtimeEffectiveMinutes: ?float, reason: string, debugMessage: string}
     */
    public function evaluateTargetReachedGate(float $elapsedWateringMinutes, ?float $runtimeEffectiveMinutes): array
    {
        $elapsedWateringMinutes = max(0.0, round($elapsedWateringMinutes, 4, PHP_ROUND_HALF_UP));

        if ($runtimeEffectiveMinutes === null || $runtimeEffectiveMinutes <= 0.0) {
            return [
                'allowed'                 => true,
                'skipZone'                => false,
                'targetReached'           => false,
                'elapsedWateringMinutes'  => $elapsedWateringMinutes,
                'runtimeEffectiveMinutes' => $runtimeEffectiveMinutes,
                'reason'                  => 'target_runtime_missing',
                'debugMessage'            => 'Target-reached check skipped: no valid effective target runtime is available.',
            ];
        }

        $runtimeEffectiveMinutes = round($runtimeEffectiveMinutes, 4, PHP_ROUND_HALF_UP);
        $targetReached = $elapsedWateringMinutes >= $runtimeEffectiveMinutes;

        if (!$targetReached) {
            return [
                'allowed'                 => true,
                'skipZone'                => false,
                'targetReached'           => false,
                'elapsedWateringMinutes'  => $elapsedWateringMinutes,
                'runtimeEffectiveMinutes' => $runtimeEffectiveMinutes,
                'reason'                  => 'target_not_reached',
                'debugMessage'            => sprintf('Target-reached check clear: elapsed=%.4f min, target=%.4f min.', $elapsedWateringMinutes, $runtimeEffectiveMinutes),
            ];
        }

        return [
            'allowed'                 => false,
            'skipZone'                => true,
            'targetReached'           => true,
            'elapsedWateringMinutes'  => $elapsedWateringMinutes,
            'runtimeEffectiveMinutes' => $runtimeEffectiveMinutes,
            'reason'                  => 'target_reached',
            'debugMessage'            => sprintf('Target-reached check blocked: elapsed=%.4f min reached or exceeded target=%.4f min.', $elapsedWateringMinutes, $runtimeEffectiveMinutes),
        ];
    }
}
