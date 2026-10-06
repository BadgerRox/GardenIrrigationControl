<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class LawnCoolingRuntimeOrchestrator
{
    /**
     * Advances one asynchronous cooling runtime slice.
     *
     * @param array<int, array<string, mixed>> $zones
     * @param array<string, callable> $callbacks
     * @return array{outcome: string, nextIndex: int, startedAt: ?int, reason: string, stopReason: string, globalStop: bool}
     */
    public function runTick(
        array $zones,
        int $currentIndex,
        ?int $startedAt,
        int $coolingDurationSeconds,
        array $callbacks,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $currentIndex = max(0, $currentIndex);
        $coolingDurationSeconds = max(1, $coolingDurationSeconds);
        $zoneWasClosedInThisTick = false;

        while ($currentIndex < count($zones)) {
            $zone = $zones[$currentIndex];
            $zoneLabel = $this->buildZoneLabel($zone);

            // Technically: re-evaluate global guards before every zone and every active runtime slice.
            // Functional behavior: mode changes, configuration errors and hard valve blockers terminate the complete cooling run.
            // Domain rationale: cooling is subordinate to safe system operation; continuing after a global change could water during maintenance or after a failed close.
            $globalResult = ($callbacks['recheckGlobal'])();
            if (!(bool) ($globalResult['allowed'] ?? false)) {
                return $this->stoppedResult(
                    $currentIndex,
                    $startedAt,
                    (string) ($globalResult['debugMessage'] ?? 'Global recheck failed.'),
                    true,
                    (string) ($globalResult['reason'] ?? '')
                );
            }

            // Technically: inspect volatile stop and skip signals before opening or continuing a zone.
            // Functional behavior: a hard stop ends immediately, while a pre-opening skip advances to the next daily terminal state.
            // Domain rationale: user and safety intent must always override a previously successful weather or soil decision.
            $signalResult = ($callbacks['checkSignals'])($startedAt !== null);
            if (!(bool) ($signalResult['allowed'] ?? false)) {
                $isHardStop = (bool) ($signalResult['hardStop'] ?? false);
                return $this->stoppedResult(
                    $currentIndex,
                    $startedAt,
                    (string) ($signalResult['debugMessage'] ?? 'Stop signal detected.'),
                    $isHardStop,
                    $isHardStop ? (string) ($signalResult['reason'] ?? '') : ''
                );
            }

            if ($startedAt !== null) {
                if ($now - $startedAt < $coolingDurationSeconds) {
                    return [
                        'outcome'    => 'active',
                        'nextIndex'  => $currentIndex,
                        'startedAt'  => $startedAt,
                        'reason'     => 'Cooling zone is still running.',
                        'stopReason' => '',
                        'globalStop' => false,
                    ];
                }

                // Technically: close the active valve set after the configured wall-clock duration.
                // Functional behavior: a completed zone is finalized before another zone can be opened.
                // Domain rationale: sequential cooling limits hydraulic load and prevents overlapping valve runtime.
                if (!(bool) ($callbacks['closeAllValves'])()) {
                    return $this->stoppedResult(
                        $currentIndex,
                        null,
                        'Valves could not be closed safely after the zone runtime elapsed.',
                        true,
                        LawnCoolingContracts::STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED
                    );
                }

                $zoneWasClosedInThisTick = true;
                $currentIndex++;
                $startedAt = null;
                continue;
            }

            // Technically: apply the start-only soil gate immediately before the valve command.
            // Functional behavior: a zone with sufficient measured moisture is skipped locally without ending other zones.
            // Domain rationale: cooling should not add water to an already moist root zone, while other dry zones may still need relief.
            $soilResult = ($callbacks['evaluateSoilStart'])($zone);
            if (!(bool) ($soilResult['allowed'] ?? false)) {
                $currentIndex++;
                continue;
            }

            // Technically: open exactly one valve and treat an opening failure as a local zone outcome.
            // Functional behavior: failed zone hardware does not abort otherwise independent cooling zones.
            // Domain rationale: one defective valve must not prevent heat relief in other zones, but no failed valve may be retried blindly in the same slice.
            if (!(bool) ($callbacks['openSingleValve'])((int) ($zone['ValveVarID'] ?? 0))) {
                $currentIndex++;
                continue;
            }

            return [
                'outcome'    => 'active',
                'nextIndex'  => $currentIndex,
                'startedAt'  => $now,
                'reason'     => sprintf('Zone %s opened.', $zoneLabel),
                'stopReason' => '',
                'globalStop' => false,
            ];
        }

        // Technically: close remaining valve state unless this tick already closed the completed zone.
        // Functional behavior: the run ends with one confirmed close command, including runs where every zone was skipped locally.
        // Domain rationale: avoiding a duplicate idempotent command reduces unnecessary hardware traffic without weakening the fail-safe boundary.
        if (!$zoneWasClosedInThisTick && !(bool) ($callbacks['closeAllValves'])()) {
            return $this->stoppedResult(
                $currentIndex,
                null,
                'Valves could not be closed safely at the end of the run.',
                true,
                LawnCoolingContracts::STOP_REASON_GLOBAL_VALVE_CLOSE_FAILED
            );
        }

        return [
            'outcome'    => 'finished',
            'nextIndex'  => $currentIndex,
            'startedAt'  => null,
            'reason'     => 'All active cooling zones were processed.',
            'stopReason' => '',
            'globalStop' => false,
        ];
    }

    /**
     * @param array<string, mixed> $zone
     */
    private function buildZoneLabel(array $zone): string
    {
        $name = isset($zone['Name']) && is_string($zone['Name']) && trim($zone['Name']) !== ''
            ? trim($zone['Name'])
            : 'unnamed';
        $sequence = isset($zone['Sequence']) && is_numeric($zone['Sequence']) ? (int) $zone['Sequence'] : -1;

        return sprintf('%s (Seq %d)', $name, $sequence);
    }

    /**
     * @return array{outcome: string, nextIndex: int, startedAt: ?int, reason: string, stopReason: string, globalStop: bool}
     */
    private function stoppedResult(
        int $currentIndex,
        ?int $startedAt,
        string $reason,
        bool $globalStop,
        string $stopReason = ''
    ): array {
        return [
            'outcome'    => 'stopped',
            'nextIndex'  => $currentIndex,
            'startedAt'  => $startedAt,
            'reason'     => $reason,
            'stopReason' => $stopReason,
            'globalStop' => $globalStop,
        ];
    }
}
