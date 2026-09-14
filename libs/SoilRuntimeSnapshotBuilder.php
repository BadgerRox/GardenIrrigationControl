<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class SoilRuntimeSnapshotBuilder
{
    /**
     * @param array<int, array<string, mixed>> $windowSamples Pre-fetched 60s archive samples for the active sensor.
     * @return array{mode: int, source: string, measuredSoil: float|null, soilMinMoisture: int, confirmWindowSeconds: int, sampleCount60s: int, confirmWindowStable: bool}
     */
    public function buildSnapshot(
        int $mode,
        int $soilMinMoisture,
        int $confirmWindowSeconds,
        string $source,
        bool $sensorExists,
        ?float $measuredSoil,
        array $windowSamples,
        DebugLoggerInterface $logger
    ): array {
        // Technically: return a normalized non-confirmed snapshot when mode/source/runtime prerequisites are missing.
        // Functional behavior: caller can bypass runtime soil stop without special-case null handling.
        // Domain rationale: missing telemetry must not produce a false positive stop that interrupts needed irrigation.
        if ($mode !== 1 || $source === 'none' || !$sensorExists) {
            return [
                'mode'                 => $mode,
                'source'               => $source,
                'measuredSoil'         => $measuredSoil,
                'soilMinMoisture'      => $soilMinMoisture,
                'confirmWindowSeconds' => $confirmWindowSeconds,
                'sampleCount60s'       => 0,
                'confirmWindowStable'  => false,
            ];
        }

        [$sampleCount, $confirmWindowStable, $unstable] = $this->evaluateWindow(
            $windowSamples,
            $soilMinMoisture,
            $source,
            $logger
        );

        // Technically: only emit the summary debug on normal window completion; the unstable early-exit path already logged its own message.
        // Functional behavior: the summary line mirrors the original module-level debug emitted after the loop.
        // Domain rationale: avoids a redundant aggregate line after an individual sample rejection message.
        if (!$unstable) {
            $logger->debug(
                'BuildSoilRuntimeSnapshot',
                sprintf(
                    'Soil-Runtime-Snapshot: mode=%d, quelle=%s, messwert=%s, grenzwert=%d%%, fenster=%ds, samples=%d, stabil=%s.',
                    $mode,
                    $source,
                    sprintf('%.2f%%', $measuredSoil ?? 0.0),
                    $soilMinMoisture,
                    $confirmWindowSeconds,
                    $sampleCount,
                    $confirmWindowStable ? 'ja' : 'nein'
                )
            );
        }

        return [
            'mode'                 => $mode,
            'source'               => $source,
            'measuredSoil'         => $measuredSoil,
            'soilMinMoisture'      => $soilMinMoisture,
            'confirmWindowSeconds' => $confirmWindowSeconds,
            'sampleCount60s'       => $sampleCount,
            'confirmWindowStable'  => $confirmWindowStable,
        ];
    }

    /**
     * Iterates archive samples and returns the window evaluation result.
     *
     * Returns an unstable flag so the caller knows whether to suppress the aggregate summary debug line.
     *
     * @param array<int, array<string, mixed>> $windowSamples
     * @return array{int, bool, bool} [sampleCount, confirmWindowStable, unstable]
     */
    private function evaluateWindow(
        array $windowSamples,
        int $soilMinMoisture,
        string $source,
        DebugLoggerInterface $logger
    ): array {
        $sampleCount = 0;

        foreach ($windowSamples as $sample) {
            if (!isset($sample['Value']) || !is_numeric($sample['Value'])) {
                continue;
            }

            $sampleCount++;
            // Technically: one numeric sample below threshold invalidates the full window immediately.
            // Functional behavior: runtime stop requires continuous above-threshold evidence, not a mean value.
            // Domain rationale: avoids overreacting to short moisture plateaus when root zone still needs water.
            if ((float) $sample['Value'] < (float) $soilMinMoisture) {
                $logger->debug(
                    'BuildSoilRuntimeSnapshot',
                    sprintf(
                        'Soil-Runtime-Fenster nicht stabil: Quelle=%s, Sample=%.2f%% unter Grenzwert=%d%%.',
                        $source,
                        (float) $sample['Value'],
                        $soilMinMoisture
                    )
                );
                return [$sampleCount, false, true];
            }
        }

        // Technically: mark window stable only if at least one numeric sample existed and none violated the threshold.
        // Functional behavior: empty windows cannot confirm a stop condition.
        // Domain rationale: absence of evidence must not be treated as evidence of sufficiently wet soil.
        return [$sampleCount, $sampleCount > 0, false];
    }
}
