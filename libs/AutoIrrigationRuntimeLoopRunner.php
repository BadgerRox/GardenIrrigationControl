<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class AutoIrrigationRuntimeLoopRunner
{
    public function __construct(
        private readonly AutoIrrigationZoneStartPlanner $planner,
        private readonly AutoIrrigationRuntimeCallbacks $callbacks
    ) {
    }

    /**
     * Executes the runtime-loop check skeleton for all prepared zones.
     *
     * @param array<int, array<string, mixed>> $activeZones
     * @return array{continue: bool, outcome: string, reason: string}
     */
    public function runPreparedZonesRuntimeLoop(array $activeZones): array
    {
        foreach ($activeZones as $zone) {
            $zoneLabel = $this->buildAutoIrrigationZoneLabel($zone);
            $checkOrder = $this->planner->getRuntimeLoopCheckOrder();

            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf('Zone %s: starting runtime check skeleton in order [%s].', $zoneLabel, implode(' -> ', $checkOrder))
            );

            $loopResult = $this->runZoneUntilDoneSkeleton($zone);
            if ($loopResult['outcome'] === AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT) {
                $this->debug(
                    'AutoIrrigationRuntimeLoop',
                    sprintf('Zone %s: zone-local stop (%s); continuing with the next zone.', $zoneLabel, (string) $loopResult['reason'])
                );
                continue;
            }

            if (!$loopResult['continue']) {
                return $loopResult;
            }
        }

        return [
            'continue' => true,
            'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_CONTINUE,
            'reason'   => 'All prepared zones were evaluated by the runtime loop.',
        ];
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}
     */
    private function runZoneUntilDoneSkeleton(array $zone): array
    {
        $zoneLabel = $this->buildAutoIrrigationZoneLabel($zone);

        // Technically: execute one deterministic skeleton cycle in the canonical order without blocking sleeps.
        // Functional behavior: this stage validates gate order and stop behavior before pause-window and timing details are added.
        // Domain rationale: staged rollout reduces risk by proving sequencing first, then extending to full runtime behavior.
        foreach ($this->planner->getRuntimeLoopCheckOrder() as $checkName) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf('Zone %s: evaluating %s.', $zoneLabel, $checkName)
            );

            $gateResult = match ($checkName) {
                'recheck_global_preconditions' => $this->checkRecheckGlobalPreconditions($zone, $zoneLabel),
                'enforce_daily_cutoff'         => $this->checkEnforceDailyCutoff($zone, $zoneLabel),
                'wind_pause_gate'              => $this->checkWindPauseGate($zone, $zoneLabel),
                'rain_pause_gate'              => $this->checkRainPauseGate($zone, $zoneLabel),
                'soil_runtime_gate_if_mode_1'  => $this->checkSoilRuntimeGate($zone, $zoneLabel),
                'target_reached_gate'          => $this->checkTargetReachedGate($zone, $zoneLabel),
                default                        => null,
            };

            if ($gateResult !== null) {
                return $gateResult;
            }
        }

        $stopCheckpoint = $this->planner->evaluateStopSignalCheckpoint((bool) ($this->callbacks->isStopRequested)(), 'runtime_loop');
        if (!$stopCheckpoint['allowed']) {
            $this->debug('AutoIrrigationRuntimeLoop', $stopCheckpoint['debugMessage']);

            ($this->callbacks->handleZoneTerminalAction)($zone, 'runtime_stop_signal');

            return [
                'continue' => false,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                'reason'   => 'Stop signal detected in the runtime loop.',
            ];
        }

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: runtime-loop stop checkpoint is clear; no stop signal is active.', $zoneLabel)
        );

        return [
            'continue' => false,
            'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_ACTIVE,
            'reason'   => 'The current zone remains active and will be evaluated again on the next runtime tick.',
        ];
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkRecheckGlobalPreconditions(array $zone, string $zoneLabel): ?array
    {
        if (!(bool) ($this->callbacks->isAutoEnabled)()) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf('Zone %s: global precheck failed; automatic mode is disabled.', $zoneLabel)
            );
            ($this->callbacks->handleZoneTerminalAction)($zone, 'auto_disabled');

            return [
                'continue' => false,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                'reason'   => 'Automatic mode was disabled during the runtime phase.',
            ];
        }

        if ((bool) ($this->callbacks->isMaintenanceEnabled)()) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf('Zone %s: global precheck failed; maintenance mode is active.', $zoneLabel)
            );
            ($this->callbacks->handleZoneTerminalAction)($zone, 'maintenance_enabled');

            return [
                'continue' => false,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                'reason'   => 'Maintenance mode was enabled during the runtime phase.',
            ];
        }

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: global precheck succeeded; automatic mode is active and maintenance mode is inactive.', $zoneLabel)
        );

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkEnforceDailyCutoff(array $zone, string $zoneLabel): ?array
    {
        $cutoffSnapshot = (array) ($this->callbacks->getDailyCutoffSnapshot)();
        $runStartTimestamp = isset($cutoffSnapshot['runStartTimestamp']) && is_numeric($cutoffSnapshot['runStartTimestamp'])
            ? (int) $cutoffSnapshot['runStartTimestamp']
            : null;
        $maxRuntimeSeconds = isset($cutoffSnapshot['maxRuntimeSeconds']) && is_numeric($cutoffSnapshot['maxRuntimeSeconds'])
            ? (int) $cutoffSnapshot['maxRuntimeSeconds']
            : 0;

        $cutoffResult = $this->planner->evaluateDailyCutoffGate($runStartTimestamp, $maxRuntimeSeconds);

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: %s', $zoneLabel, (string) $cutoffResult['debugMessage'])
        );

        if (!$cutoffResult['allowed']) {
            ($this->callbacks->handleZoneTerminalAction)($zone, 'daily_cutoff');

            return [
                'continue' => false,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                'reason'   => 'Daily cutoff reached.',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkWindPauseGate(array $zone, string $zoneLabel): ?array
    {
        $windSnapshot = (array) ($this->callbacks->getWindPauseSnapshot)();
        $windPauseTriggered = (bool) ($windSnapshot['windPauseTriggered'] ?? false);
        $resumeStable = (bool) ($windSnapshot['resumeStable'] ?? false);
        $pauseActive = (bool) ($windSnapshot['pauseActive'] ?? false);
        $triggerMeanWind = (float) ($windSnapshot['triggerMeanWind'] ?? 0.0);
        $thresholdWindMaxSpeed = (float) ($windSnapshot['thresholdWindMaxSpeed'] ?? 0.0);

        return $this->evaluatePauseGate(
            zone: $zone,
            zoneLabel: $zoneLabel,
            pauseTriggered: $windPauseTriggered,
            resumeStable: $resumeStable,
            pauseActive: $pauseActive,
            triggerValue: $triggerMeanWind,
            thresholdValue: $thresholdWindMaxSpeed,
            pauseReasonKey: 'wind_pause',
            pausedRunState: AutoIrrigationContracts::RUN_STATE_PAUSED_WIND,
            pausedOutcome: AutoIrrigationContracts::RUNTIME_OUTCOME_PAUSED_WIND,
            pausedReasonMessage: 'Wind pause remains active; the resume window is not stable yet.',
            resumeRunStateReason: 'Wind pause ended: resume window is stable.',
            inactiveDebugMessageTemplate: 'Zone %s: wind pause is inactive (5-minute mean %.2f <= limit %.2f).',
            pauseSetDebugMessageTemplate: 'Zone %s: wind pause activated (5-minute mean %.2f > limit %.2f).',
            pauseStillActiveMessage: 'Zone %s: wind pause remains active; monitoring the resume window.',
            pauseStillActiveOutcomeMessage: 'Zone %s: wind pause remains active; the 15-minute resume window is not stable yet.',
            resumeStableDebugMessage: 'Zone %s: wind pause cleared; the 15-minute resume window is stable.',
            reentryBlockedDebugMessageTemplate: 'Zone %s: resume re-entry after wind pause was blocked (%s).',
            resumeSuccessDebugMessage: 'Zone %s: resume re-entry after wind pause succeeded; the zone continues.',
            pauseActivatedRunStateReason: 'Wind pause active: wind limit exceeded.',
            resumeStopReason: 'wind_pause_resume_stop',
            resumeStopOutcomeReason: 'Stop signal detected after wind resume.'
        );
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkRainPauseGate(array $zone, string $zoneLabel): ?array
    {
        $rainSnapshot = (array) ($this->callbacks->getRainPauseSnapshot)();
        $rainPauseTriggered = (bool) ($rainSnapshot['rainPauseTriggered'] ?? false);
        $resumeDryStable = (bool) ($rainSnapshot['resumeDryStable'] ?? false);
        $pauseActive = (bool) ($rainSnapshot['pauseActive'] ?? false);
        $triggerRainSum5m = (float) ($rainSnapshot['triggerRainSum5m'] ?? 0.0);
        $thresholdRainSum5m = (float) ($rainSnapshot['thresholdRainSum5m'] ?? 0.2);

        return $this->evaluatePauseGate(
            zone: $zone,
            zoneLabel: $zoneLabel,
            pauseTriggered: $rainPauseTriggered,
            resumeStable: $resumeDryStable,
            pauseActive: $pauseActive,
            triggerValue: $triggerRainSum5m,
            thresholdValue: $thresholdRainSum5m,
            pauseReasonKey: 'rain_pause',
            pausedRunState: AutoIrrigationContracts::RUN_STATE_PAUSED_RAIN,
            pausedOutcome: AutoIrrigationContracts::RUNTIME_OUTCOME_PAUSED_RAIN,
            pausedReasonMessage: 'Rain pause remains active; the dry resume window is not stable yet.',
            resumeRunStateReason: 'Rain pause ended: dry window is stable.',
            inactiveDebugMessageTemplate: 'Zone %s: rain pause is inactive (5-minute sum %.2f <= limit %.2f).',
            pauseSetDebugMessageTemplate: 'Zone %s: rain pause activated (5-minute sum %.2f > limit %.2f).',
            pauseStillActiveMessage: 'Zone %s: rain pause remains active; monitoring the dry resume window.',
            pauseStillActiveOutcomeMessage: 'Zone %s: rain pause remains active; the 15-minute dry window is not stable yet.',
            resumeStableDebugMessage: 'Zone %s: rain pause cleared; the 15-minute dry window is stable.',
            reentryBlockedDebugMessageTemplate: 'Zone %s: resume re-entry after rain pause was blocked (%s).',
            resumeSuccessDebugMessage: 'Zone %s: resume re-entry after rain pause succeeded; the zone continues.',
            pauseActivatedRunStateReason: 'Rain pause active: rain limit exceeded.',
            resumeStopReason: 'rain_pause_resume_stop',
            resumeStopOutcomeReason: 'Stop signal detected after rain resume.'
        );
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function evaluatePauseGate(
        array $zone,
        string $zoneLabel,
        bool $pauseTriggered,
        bool $resumeStable,
        bool $pauseActive,
        float $triggerValue,
        float $thresholdValue,
        string $pauseReasonKey,
        string $pausedRunState,
        string $pausedOutcome,
        string $pausedReasonMessage,
        string $resumeRunStateReason,
        string $inactiveDebugMessageTemplate,
        string $pauseSetDebugMessageTemplate,
        string $pauseStillActiveMessage,
        string $pauseStillActiveOutcomeMessage,
        string $resumeStableDebugMessage,
        string $reentryBlockedDebugMessageTemplate,
        string $resumeSuccessDebugMessage,
        string $pauseActivatedRunStateReason,
        string $resumeStopReason,
        string $resumeStopOutcomeReason
    ): ?array {
        if (!$pauseTriggered && !$pauseActive) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf($inactiveDebugMessageTemplate, $zoneLabel, $triggerValue, $thresholdValue)
            );

            return null;
        }

        if ($pauseTriggered && !$pauseActive) {
            ($this->callbacks->setRunState)($pausedRunState, $pauseActivatedRunStateReason);
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf($pauseSetDebugMessageTemplate, $zoneLabel, $triggerValue, $thresholdValue)
            );

            ($this->callbacks->handleZonePauseAction)($zone, $pauseReasonKey);
        } elseif ($pauseActive) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf($pauseStillActiveMessage, $zoneLabel)
            );
        }

        if (!$resumeStable) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf($pauseStillActiveOutcomeMessage, $zoneLabel)
            );

            return [
                'continue' => false,
                'outcome'  => $pausedOutcome,
                'reason'   => $pausedReasonMessage,
            ];
        }

        ($this->callbacks->setRunState)(AutoIrrigationContracts::RUN_STATE_RUNNING, $resumeRunStateReason);
        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf($resumeStableDebugMessage, $zoneLabel)
        );

        if ((bool) ($this->callbacks->isStopRequested)()) {
            ($this->callbacks->handleZoneTerminalAction)($zone, $resumeStopReason);

            return [
                'continue' => false,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                'reason'   => $resumeStopOutcomeReason,
            ];
        }

        $reentryResult = (array) ($this->callbacks->runResumeReentryPipeline)($zone, $pauseReasonKey);
        if (!((bool) ($reentryResult['allowed'] ?? false))) {
            $this->debug(
                'AutoIrrigationRuntimeLoop',
                sprintf($reentryBlockedDebugMessageTemplate, $zoneLabel, (string) ($reentryResult['reason'] ?? 'unknown'))
            );

            if ((bool) ($reentryResult['globalStop'] ?? false)) {
                ($this->callbacks->handleZoneTerminalAction)($zone, (string) ($reentryResult['reason'] ?? 'resume_reentry_global_stop'));

                return [
                    'continue' => false,
                    'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_STOPPED,
                    'reason'   => (string) ($reentryResult['reason'] ?? 'Resume re-entry triggered a global stop.'),
                ];
            }

            ($this->callbacks->handleZoneTerminalAction)($zone, (string) ($reentryResult['reason'] ?? 'resume_reentry_zone_stop'));

            return [
                'continue' => true,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT,
                'reason'   => (string) ($reentryResult['reason'] ?? 'Resume re-entry required a zone-local stop.'),
            ];
        }

        $resumeActionResult = (array) ($this->callbacks->handleZoneResumeAction)($zone, $pauseReasonKey);
        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: %s', $zoneLabel, (string) ($resumeActionResult['debugMessage'] ?? 'Resume reopen was processed without a detail message.'))
        );

        if (!((bool) ($resumeActionResult['opened'] ?? false))) {
            ($this->callbacks->handleZoneTerminalAction)($zone, (string) ($resumeActionResult['reason'] ?? 'resume_reopen_failed'));

            return [
                'continue' => true,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT,
                'reason'   => (string) ($resumeActionResult['reason'] ?? 'Resume reopen required a zone-local stop.'),
            ];
        }

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf($resumeSuccessDebugMessage, $zoneLabel)
        );

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkSoilRuntimeGate(array $zone, string $zoneLabel): ?array
    {
        // Technically: read one normalized soil runtime snapshot from the module callback.
        // Functional behavior: the runner evaluates soil stop decisions from a deterministic payload contract.
        // Domain rationale: stable data contracts reduce coupling and prevent hidden behavior drift across runtime steps.
        $soilSnapshot = (array) ($this->callbacks->getSoilRuntimeSnapshot)($zone);
        $mode = isset($soilSnapshot['mode']) && is_numeric($soilSnapshot['mode'])
            ? (int) $soilSnapshot['mode']
            : 0;
        $source = isset($soilSnapshot['source']) && is_string($soilSnapshot['source'])
            ? $soilSnapshot['source']
            : 'none';
        $measuredSoil = isset($soilSnapshot['measuredSoil']) && is_numeric($soilSnapshot['measuredSoil'])
            ? (float) $soilSnapshot['measuredSoil']
            : null;
        $soilMinMoisture = isset($soilSnapshot['soilMinMoisture']) && is_numeric($soilSnapshot['soilMinMoisture'])
            ? (int) $soilSnapshot['soilMinMoisture']
            : 0;
        $confirmWindowStable = (bool) ($soilSnapshot['confirmWindowStable'] ?? false);
        $sampleCount = isset($soilSnapshot['sampleCount60s']) && is_numeric($soilSnapshot['sampleCount60s'])
            ? (int) $soilSnapshot['sampleCount60s']
            : 0;
        $confirmWindowSeconds = isset($soilSnapshot['confirmWindowSeconds']) && is_numeric($soilSnapshot['confirmWindowSeconds'])
            ? (int) $soilSnapshot['confirmWindowSeconds']
            : 60;

        // Technically: delegate the gate decision to planner logic with explicit mode/threshold/window inputs.
        // Functional behavior: only a confirmed above-threshold window can block the current zone.
        // Domain rationale: irrigation must continue through noisy spikes and stop only on sustained wetness evidence.
        $soilResult = $this->planner->evaluateSoilRuntimeGate(
            $mode,
            $source,
            $measuredSoil,
            $soilMinMoisture,
            $confirmWindowStable,
            $sampleCount,
            $confirmWindowSeconds
        );

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: %s', $zoneLabel, (string) $soilResult['debugMessage'])
        );

        // Technically: map a blocking soil result to zone_stop_and_next and keep global run active.
        // Functional behavior: the current zone ends locally, then orchestration proceeds with the next zone.
        // Domain rationale: sufficient soil moisture in one zone must not suppress watering demand in other zones.
        if (!$soilResult['allowed']) {
            ($this->callbacks->handleZoneTerminalAction)($zone, (string) $soilResult['reason']);

            return [
                'continue' => true,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT,
                'reason'   => (string) $soilResult['reason'],
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{continue: bool, outcome: string, reason: string}|null
     */
    private function checkTargetReachedGate(array $zone, string $zoneLabel): ?array
    {
        // Technically: evaluate elapsed watering time against effective runtime using a normalized target snapshot.
        // Functional behavior: once target runtime is reached, only the current zone is stopped and the run continues.
        // Domain rationale: precise target-stop prevents overwatering while still allowing other zones to be processed.
        $targetSnapshot = (array) ($this->callbacks->getTargetReachedSnapshot)($zone);
        $elapsedWateringMinutes = isset($targetSnapshot['elapsedWateringMinutes']) && is_numeric($targetSnapshot['elapsedWateringMinutes'])
            ? (float) $targetSnapshot['elapsedWateringMinutes']
            : 0.0;
        $runtimeEffectiveMinutes = isset($targetSnapshot['runtimeEffectiveMinutes']) && is_numeric($targetSnapshot['runtimeEffectiveMinutes'])
            ? (float) $targetSnapshot['runtimeEffectiveMinutes']
            : null;

        $targetResult = $this->planner->evaluateTargetReachedGate($elapsedWateringMinutes, $runtimeEffectiveMinutes);

        $this->debug(
            'AutoIrrigationRuntimeLoop',
            sprintf('Zone %s: %s', $zoneLabel, (string) $targetResult['debugMessage'])
        );

        if (!$targetResult['allowed']) {
            ($this->callbacks->handleZoneTerminalAction)($zone, (string) $targetResult['reason']);

            return [
                'continue' => true,
                'outcome'  => AutoIrrigationContracts::RUNTIME_OUTCOME_ZONE_STOP_AND_NEXT,
                'reason'   => (string) $targetResult['reason'],
            ];
        }

        return null;
    }

    /**
     * Builds a short zone label for deterministic debug output.
     *
     * @param array<string, mixed> $zone
     */
    private function buildAutoIrrigationZoneLabel(array $zone): string
    {
        $name = isset($zone['Name']) && is_string($zone['Name']) && trim($zone['Name']) !== ''
            ? trim($zone['Name'])
            : 'unnamed';

        $sequence = isset($zone['Sequence']) && is_numeric($zone['Sequence'])
            ? (int) $zone['Sequence']
            : -1;

        return sprintf('%s (Seq %d)', $name, $sequence);
    }

    private function debug(string $context, string $message): void
    {
        ($this->callbacks->debugLogger)($context, $message);
    }
}
