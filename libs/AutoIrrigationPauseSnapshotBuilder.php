<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class AutoIrrigationPauseSnapshotBuilder
{
    /**
     * @param array<int, array<string, mixed>> $window5mSamples
     * @param array<int, array<string, mixed>> $window15mSamples
     * @return array{windPauseTriggered: bool, resumeStable: bool, pauseActive: bool, triggerMeanWind: float, thresholdWindMaxSpeed: float, sampleCount5m: int, sampleCount15m: int}
     */
    public function buildWindPauseSnapshot(
        array $window5mSamples,
        array $window15mSamples,
        float $thresholdWindMaxSpeed,
        bool $pauseActive
    ): array {
        $triggerMeanWind = $this->calculateWindWindowMean($window5mSamples);
        $windPauseTriggered = $triggerMeanWind > $thresholdWindMaxSpeed;
        $resumeStable = $this->isWindResumeWindowStable($window15mSamples, $thresholdWindMaxSpeed);

        return [
            'windPauseTriggered'    => $windPauseTriggered,
            'resumeStable'          => $resumeStable,
            'pauseActive'           => $pauseActive,
            'triggerMeanWind'       => $triggerMeanWind,
            'thresholdWindMaxSpeed' => $thresholdWindMaxSpeed,
            'sampleCount5m'         => count($window5mSamples),
            'sampleCount15m'        => count($window15mSamples),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $window5mSamples
     * @param array<int, array<string, mixed>> $window15mSamples
     * @return array{rainPauseTriggered: bool, resumeDryStable: bool, pauseActive: bool, triggerRainSum5m: float, thresholdRainSum5m: float, sampleCount5m: int, sampleCount15m: int}
     */
    public function buildRainPauseSnapshot(
        array $window5mSamples,
        array $window15mSamples,
        float $thresholdRainSum5m,
        bool $pauseActive
    ): array {
        $triggerRainSum5m = $this->calculateRainWindowSum($window5mSamples);
        $rainPauseTriggered = $triggerRainSum5m > $thresholdRainSum5m;
        $resumeDryStable = $this->isRainResumeWindowDry($window15mSamples);

        return [
            'rainPauseTriggered' => $rainPauseTriggered,
            'resumeDryStable'    => $resumeDryStable,
            'pauseActive'        => $pauseActive,
            'triggerRainSum5m'   => $triggerRainSum5m,
            'thresholdRainSum5m' => $thresholdRainSum5m,
            'sampleCount5m'      => count($window5mSamples),
            'sampleCount15m'     => count($window15mSamples),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     */
    private function calculateWindWindowMean(array $samples): float
    {
        $sum = 0.0;
        $count = 0;

        foreach ($samples as $sample) {
            if (!isset($sample['Value']) || !is_numeric($sample['Value'])) {
                continue;
            }

            $sum += (float) $sample['Value'];
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     */
    private function isWindResumeWindowStable(array $samples, float $thresholdWindMaxSpeed): bool
    {
        $numericSampleCount = 0;

        foreach ($samples as $sample) {
            if (!isset($sample['Value']) || !is_numeric($sample['Value'])) {
                continue;
            }

            $numericSampleCount++;
            if ((float) $sample['Value'] > $thresholdWindMaxSpeed) {
                return false;
            }
        }

        return $numericSampleCount > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     */
    private function calculateRainWindowSum(array $samples): float
    {
        $sum = 0.0;

        foreach ($samples as $sample) {
            if (!isset($sample['Value']) || !is_numeric($sample['Value'])) {
                continue;
            }

            $value = (float) $sample['Value'];
            if ($value > 0.0) {
                $sum += $value;
            }
        }

        return round($sum, 4, PHP_ROUND_HALF_UP);
    }

    /**
     * @param array<int, array<string, mixed>> $samples
     */
    private function isRainResumeWindowDry(array $samples): bool
    {
        $numericSampleCount = 0;

        foreach ($samples as $sample) {
            if (!isset($sample['Value']) || !is_numeric($sample['Value'])) {
                continue;
            }

            $numericSampleCount++;
            if ((float) $sample['Value'] > 0.0) {
                return false;
            }
        }

        return $numericSampleCount > 0;
    }
}
