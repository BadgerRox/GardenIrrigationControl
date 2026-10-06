<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use DateTimeImmutable;

final class LawnCoolingTemperatureGate
{
    private const REQUIRED_DURATION_SECONDS = 300;

    /**
     * Evaluates whether archived temperatures stayed at or above the threshold for five minutes.
     *
     * Technically: treats each archive value as valid until the next sample or the evaluation end.
     * Functional behavior: only a continuous threshold event allows lawn cooling to proceed.
     * Domain rationale: a brief sensor spike does not prove sustained heat stress and must not trigger cooling.
     *
     * @param array<int, array<string, mixed>> $archiveValues
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    public function evaluate(array $archiveValues, int $threshold, DateTimeImmutable $todayStart, DateTimeImmutable $now): array
    {
        $startTimestamp = $todayStart->getTimestamp();
        $endTimestamp = $now->getTimestamp();
        $previousTimestamp = $startTimestamp;
        $hotSince = null;
        $validSamples = 0;

        // Technically: order archive samples by timestamp before evaluating continuity.
        // Functional behavior: ascending and descending archive responses produce the same five-minute decision.
        // Domain rationale: archive APIs may return newest-first data; evaluating that order would split real heat events and falsely block cooling.
        usort(
            $archiveValues,
            static function (array $left, array $right): int
            {
                $leftTimestamp = $left['TimeStamp'] ?? $left['Time'] ?? $left['time'] ?? null;
                $rightTimestamp = $right['TimeStamp'] ?? $right['Time'] ?? $right['time'] ?? null;

                if (!is_numeric($leftTimestamp) || !is_numeric($rightTimestamp)) {
                    return 0;
                }

                return (int) $leftTimestamp <=> (int) $rightTimestamp;
            }
        );

        foreach ($archiveValues as $archiveValue) {
            $timestamp = $this->extractTimestamp($archiveValue);
            $temperature = $this->extractNumericValue($archiveValue);
            if ($timestamp === null || $temperature === null || $timestamp < $startTimestamp || $timestamp > $endTimestamp) {
                continue;
            }

            $validSamples++;
            if ($hotSince !== null && $timestamp > $previousTimestamp) {
                $duration = $timestamp - $hotSince;
                if ($duration >= self::REQUIRED_DURATION_SECONDS) {
                    return $this->allowedResult($duration);
                }
            }

            if ($temperature >= $threshold) {
                if ($hotSince === null) {
                    $hotSince = $timestamp;
                }
            } else {
                $hotSince = null;
            }

            $previousTimestamp = $timestamp;
        }

        if ($hotSince !== null) {
            $duration = $endTimestamp - $hotSince;
            if ($duration >= self::REQUIRED_DURATION_SECONDS) {
                return $this->allowedResult($duration);
            }
        }

        if ($validSamples === 0) {
            return [
                'allowed'      => false,
                'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_NO_VALID_TEMPERATURE_ARCHIVE,
                'debugMessage' => sprintf('Temperature check BLOCK: no valid temperature archive values for today; CoolingTemp %.1f °C was not evaluated.', (float) $threshold),
            ];
        }

        return [
            'allowed'      => false,
            'reason'       => LawnCoolingContracts::STOP_REASON_ZONE_TEMP_THRESHOLD_NOT_REACHED,
            'debugMessage' => sprintf('Temperature check BLOCK: CoolingTemp %.1f °C was not reached continuously for at least 5 minutes today.', (float) $threshold),
        ];
    }

    /**
     * @param array<string, mixed> $archiveValue
     */
    private function extractTimestamp(array $archiveValue): ?int
    {
        $timestamp = $archiveValue['TimeStamp'] ?? $archiveValue['Time'] ?? $archiveValue['time'] ?? null;
        if (!is_int($timestamp) && !is_numeric($timestamp)) {
            return null;
        }

        return (int) $timestamp;
    }

    /**
     * @param array<string, mixed> $archiveValue
     */
    private function extractNumericValue(array $archiveValue): ?float
    {
        $value = $archiveValue['Value'] ?? $archiveValue['value'] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array{allowed: bool, reason: string, debugMessage: string}
     */
    private function allowedResult(int $duration): array
    {
        return [
            'allowed'      => true,
            'reason'       => 'ALLOW',
            'debugMessage' => sprintf('Temperature check ALLOW: CoolingTemp was reached continuously for at least %s; cooling is allowed by temperature.', $this->formatDuration($duration)),
        ];
    }

    private function formatDuration(int $duration): string
    {
        $hours = intdiv($duration, 3600);
        $minutes = intdiv($duration % 3600, 60);
        $seconds = $duration % 60;

        if ($hours > 0) {
            return sprintf('%d h %02d min', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%d min %02d s', $minutes, $seconds);
        }

        return sprintf('%d s', $seconds);
    }
}
