<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use DateTimeImmutable;

final class LawnCoolingRainGate
{
    private const RAIN_THRESHOLD_MM = 2.0;

    /**
     * Evaluates the six-hour rainfall result with an explicit fail-open fallback.
     *
     * Technically: compares a nullable rainfall amount with the strict > 2.0 mm limit.
     * Functional behavior: only measurable rainfall above the limit blocks cooling; unavailable data allows it.
     * Domain rationale: heavy recent rain makes cooling redundant, while missing telemetry must not suppress heat relief.
     *
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    public function evaluate(?float $rainfallMm): array
    {
        if ($rainfallMm === null) {
            return [
                'allowed'      => true,
                'reason'       => 'ALLOW',
                'debugMessage' => 'Rain check ALLOW: no usable rainfall value for the last 6 hours; fail-open fallback is active.',
            ];
        }

        if ($rainfallMm > self::RAIN_THRESHOLD_MM) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_RAIN_6H_EXCEEDED,
                'debugMessage' => sprintf('Rain check BLOCK: %.2f mm rainfall in the last 6 hours; limit of 2.00 mm exceeded.', $rainfallMm),
            ];
        }

        return [
            'allowed'      => true,
            'reason'       => 'ALLOW',
            'debugMessage' => sprintf('Rain check ALLOW: %.2f mm rainfall in the last 6 hours; limit of 2.00 mm not exceeded.', $rainfallMm),
        ];
    }

    /**
     * Calculates rainfall from a cumulative daily counter over an arbitrary interval.
     *
     * @param array<int, array<string, mixed>> $archiveValues
     */
    public function calculateDayCounterRainfall(array $archiveValues, DateTimeImmutable $start, DateTimeImmutable $end): ?float
    {
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $end->getTimestamp();
        $values = [];

        foreach ($archiveValues as $archiveValue) {
            $timestamp = $archiveValue['TimeStamp'] ?? null;
            $value = $archiveValue['Value'] ?? null;
            if (!is_numeric($timestamp) || !is_numeric($value)) {
                continue;
            }

            $sampleTimestamp = (int) $timestamp;
            if ($sampleTimestamp <= $endTimestamp) {
                $values[] = ['timestamp' => $sampleTimestamp, 'value' => (float) $value];
            }
        }

        if ($values === []) {
            return null;
        }

        usort($values, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);

        $previousValue = null;
        $rainfall = 0.0;
        $hasWindowSample = false;
        foreach ($values as $sample) {
            if ($sample['timestamp'] < $startTimestamp) {
                $previousValue = $sample['value'];
                continue;
            }

            if ($sample['timestamp'] > $endTimestamp) {
                break;
            }

            $hasWindowSample = true;
            if ($previousValue !== null) {
                $delta = $sample['value'] - $previousValue;
                $rainfall += $delta >= 0.0 ? $delta : $sample['value'];
            }
            $previousValue = $sample['value'];
        }

        return $hasWindowSample && $previousValue !== null ? round(max(0.0, $rainfall), 4) : null;
    }
}
