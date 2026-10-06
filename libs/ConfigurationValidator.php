<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

final class ConfigurationValidator
{
    public function validateRequiredBaseFields(
        string $systemType,
        string $locationJson,
        int $temperatureVarId,
        int $humidityVarId,
        int $rainVarId,
        int $pressureVarId,
        int $windVarId
    ): ValidationResult {
        if ($systemType === '' || $locationJson === '' || $temperatureVarId === 0 || $humidityVarId === 0 || $rainVarId === 0 || $pressureVarId === 0 || $windVarId === 0) {
            return $this->failure(104, 'status.waiting_for_configuration');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $locationData
     */
    public function validateLocation(array $locationData): ValidationResult
    {
        if (empty($locationData)
            || !isset($locationData['latitude'], $locationData['longitude'])
            || !is_numeric($locationData['latitude'])
            || !is_numeric($locationData['longitude'])
        ) {
            return $this->failure(201, 'status.configuration.invalid_location');
        }

        $latitude = (float) $locationData['latitude'];
        $longitude = (float) $locationData['longitude'];

        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return $this->failure(201, 'status.configuration.invalid_location');
        }

        return $this->success();
    }

    public function validateWindConfiguration(float $windHeight, int $windMaxSpeed): ValidationResult
    {
        if ($windHeight < 0.1 || $windHeight > 100.0) {
            return $this->failure(206, 'status.configuration.invalid_wind_height');
        }

        if ($windMaxSpeed < 1 || $windMaxSpeed > 200) {
            return $this->failure(207, 'status.configuration.invalid_max_wind_speed');
        }

        return $this->success();
    }

    public function validateBaseline(float $baselineETO, int $startMonth, int $endMonth): ValidationResult
    {
        if ($baselineETO < 0.1 || $baselineETO > 50.0) {
            return $this->failure(210, 'status.configuration.invalid_baseline_eto');
        }

        if ($startMonth < 1 || $startMonth > 12 || $endMonth < 1 || $endMonth > 12) {
            return $this->failure(211, 'status.configuration.invalid_baseline_months');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $irrigationStartTime
     * @param array<string, mixed> $irrigationMaxRuntime
     */
    public function validateRuntimeBudget(
        int $irrigationInterval,
        array $irrigationStartTime,
        array $irrigationMaxRuntime,
        float $irrigationMinRuntime,
        int $irrigationZoneMaxRuntime
    ): ValidationResult {
        if ($irrigationInterval < 1 || $irrigationInterval > 14) {
            return $this->failure(230, 'status.configuration.invalid_irrigation_interval');
        }

        if (!$this->isValidTimeOfDay($irrigationStartTime)) {
            return $this->failure(231, 'status.configuration.invalid_irrigation_start_time');
        }

        if (empty($irrigationMaxRuntime) || !isset($irrigationMaxRuntime['hour'])) {
            return $this->failure(232, 'status.configuration.invalid_irrigation_max_runtime');
        }

        if ($irrigationMinRuntime < 1.0 || $irrigationMinRuntime > 60.0) {
            return $this->failure(233, 'status.configuration.invalid_irrigation_min_runtime');
        }

        if ($irrigationZoneMaxRuntime < 1 || $irrigationZoneMaxRuntime > 360) {
            return $this->failure(234, 'status.configuration.invalid_zone_max_runtime');
        }

        if ((float) $irrigationZoneMaxRuntime <= $irrigationMinRuntime) {
            return $this->failure(235, 'status.configuration.zone_max_runtime_below_minimum');
        }

        $irrigationMaxRuntimeSeconds =
            ((is_numeric($irrigationMaxRuntime['hour']) ? (int) $irrigationMaxRuntime['hour'] : 0) * 3600)
            + ((isset($irrigationMaxRuntime['minute']) && is_numeric($irrigationMaxRuntime['minute']) ? (int) $irrigationMaxRuntime['minute'] : 0) * 60)
            + (isset($irrigationMaxRuntime['second']) && is_numeric($irrigationMaxRuntime['second']) ? (int) $irrigationMaxRuntime['second'] : 0);

        $zoneMaxRuntimeSeconds = $irrigationZoneMaxRuntime * 60;
        $minRuntimeSeconds = $irrigationMinRuntime * 60.0;

        if ((float) $irrigationMaxRuntimeSeconds <= (float) $zoneMaxRuntimeSeconds || (float) $irrigationMaxRuntimeSeconds <= $minRuntimeSeconds) {
            return $this->failure(236, 'status.configuration.irrigation_max_runtime_too_short');
        }

        return $this->success();
    }

    public function validateSoilMoistureConfiguration(
        int $globalSoilMoistureVarId,
        bool $globalSoilMoistureExists,
        int $soilMinMoisture,
        int $soilMoistureMode
    ): ValidationResult {
        if ($globalSoilMoistureVarId > 0 && !$globalSoilMoistureExists) {
            return $this->failure(220, 'status.configuration.invalid_global_soil_moisture_variable');
        }

        if ($soilMinMoisture < 20 || $soilMinMoisture > 100) {
            return $this->failure(221, 'status.configuration.invalid_soil_moisture_threshold');
        }

        if ($soilMoistureMode < 0 || $soilMoistureMode > 1) {
            return $this->failure(222, 'status.configuration.invalid_soil_moisture_mode');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $coolingStartTime
     * @param array<string, mixed> $irrigationStartTime
     * @param array<string, mixed> $irrigationMaxRuntime
     */
    public function validateCoolingConfiguration(
        string $systemType,
        bool $coolingEnable,
        array $coolingStartTime,
        int $coolingTime,
        int $coolingTemp,
        array $irrigationStartTime = [],
        array $irrigationMaxRuntime = [],
        bool $coolingPushEnable = false,
        int $coolingPushInstance = 0
    ): ValidationResult {
        if ($systemType !== 'lawn') {
            return $this->success();
        }

        if (!$this->isValidTimeOfDay($coolingStartTime)) {
            return $this->failure(240, 'status.configuration.invalid_lawn_cooling_start_time');
        }

        if ($coolingTime < 1 || $coolingTime > 20) {
            return $this->failure(241, 'status.configuration.invalid_lawn_cooling_runtime');
        }

        if ($coolingTemp < 20 || $coolingTemp > 50) {
            return $this->failure(242, 'status.configuration.invalid_lawn_cooling_temperature');
        }

        if ($coolingPushEnable && $coolingPushInstance <= 0) {
            return $this->failure(244, 'status.configuration.invalid_lawn_cooling_push_instance');
        }

        if ($coolingPushEnable && IPS_InstanceExists($coolingPushInstance)) {
            $pushInstance = IPS_GetInstance($coolingPushInstance);
            $moduleId = $pushInstance['ModuleInfo']['ModuleID'] ?? '';
            if ($moduleId !== '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}') {
                return $this->failure(245, 'status.configuration.invalid_lawn_cooling_visualization_instance');
            }
        }

        if ($coolingPushEnable && !IPS_InstanceExists($coolingPushInstance)) {
            return $this->failure(244, 'status.configuration.invalid_lawn_cooling_push_instance');
        }

        if (!$coolingEnable) {
            return $this->success();
        }

        if (empty($irrigationStartTime) || !isset($irrigationStartTime['hour'])) {
            return $this->success();
        }

        if (empty($irrigationMaxRuntime) || !isset($irrigationMaxRuntime['hour'])) {
            return $this->success();
        }

        if ($this->coolingWindowsOverlap($coolingStartTime, $coolingTime, $irrigationStartTime, $irrigationMaxRuntime)) {
            return $this->failure(243, 'status.configuration.overlapping_lawn_cooling_and_irrigation');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $coolingStartTime
     * @param array<string, mixed> $irrigationStartTime
     * @param array<string, mixed> $irrigationMaxRuntime
     */
    public function coolingWindowsOverlap(
        array $coolingStartTime,
        int $coolingTime,
        array $irrigationStartTime,
        array $irrigationMaxRuntime
    ): bool {
        // Technically: compare both configured intervals as seconds since midnight.
        // Functional behavior: reject a cooling cycle when any part overlaps normal irrigation.
        // Domain rationale: concurrent watering would distort soil hydration and the daily water budget.
        $coolingStartSeconds = ($this->extractTimeComponent($coolingStartTime, 'hour') * 3600)
            + ($this->extractTimeComponent($coolingStartTime, 'minute') * 60)
            + $this->extractTimeComponent($coolingStartTime, 'second');
        $coolingEndSeconds = $coolingStartSeconds + ($coolingTime * 60);

        $irrigationStartSeconds = ($this->extractTimeComponent($irrigationStartTime, 'hour') * 3600)
            + ($this->extractTimeComponent($irrigationStartTime, 'minute') * 60)
            + $this->extractTimeComponent($irrigationStartTime, 'second');
        $irrigationRuntimeSeconds = ($this->extractTimeComponent($irrigationMaxRuntime, 'hour') * 3600)
            + ($this->extractTimeComponent($irrigationMaxRuntime, 'minute') * 60)
            + $this->extractTimeComponent($irrigationMaxRuntime, 'second');
        $irrigationEndSeconds = $irrigationStartSeconds + $irrigationRuntimeSeconds;
        $coolingIntervals = $this->splitDailyInterval($coolingStartSeconds, $coolingEndSeconds);
        $irrigationIntervals = $this->splitDailyInterval($irrigationStartSeconds, $irrigationEndSeconds);

        foreach ($coolingIntervals as $coolingInterval) {
            foreach ($irrigationIntervals as $irrigationInterval) {
                if ($coolingInterval[0] < $irrigationInterval[1] && $irrigationInterval[0] < $coolingInterval[1]) {
                    return true;
                }
            }
        }

        return false;
    }

    public function validateMaintenanceExclusivity(bool $systemAutoEnable, bool $systemMaintenanceEnable): ValidationResult
    {
        if ($systemAutoEnable === true && $systemMaintenanceEnable === true) {
            return $this->failure(300, 'status.runtime.auto_and_maintenance_conflict');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<int, string> $validOrientations
     */
    public function validateZoneBeforeArchiveChecks(array $zone, array $validOrientations): ValidationResult
    {
        if (!isset($zone['Name']) || trim((string) $zone['Name']) === '' || strlen(trim((string) $zone['Name'])) < 2) {
            return $this->failure(251, 'status.zone.name_too_short');
        }

        if (!isset($zone['Orientation']) || !in_array($zone['Orientation'], $validOrientations, true)) {
            return $this->failure(252, 'status.zone.invalid_orientation');
        }

        if (!isset($zone['ValveVarID']) || (int) $zone['ValveVarID'] === 0) {
            return $this->failure(253, 'status.zone.invalid_valve');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<int, int|string> $alreadyUsedValveIds
     */
    public function validateZoneValveUniqueness(array $zone, array $alreadyUsedValveIds): ValidationResult
    {
        if (in_array($zone['ValveVarID'], $alreadyUsedValveIds, true)) {
            return $this->failure(260, 'status.zone.duplicate_valve');
        }

        return $this->success();
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<int, int|string> $alreadyUsedSequences
     */
    public function validateZonePostArchiveChecks(array $zone, int $globalSoilMoistureVarId, array $alreadyUsedSequences): ValidationResult
    {
        if (isset($zone['UseGlobalSoilMoisture']) && $zone['UseGlobalSoilMoisture'] && $globalSoilMoistureVarId === 0) {
            return $this->failure(255, 'status.zone.global_soil_moisture_missing');
        }

        if (!isset($zone['Sequence']) || $zone['Sequence'] < 1 || $zone['Sequence'] > 100) {
            return $this->failure(256, 'status.zone.invalid_sequence');
        }

        if (in_array($zone['Sequence'], $alreadyUsedSequences, true)) {
            return $this->failure(259, 'status.zone.duplicate_sequence');
        }

        if (!isset($zone['sprinklerPrecipitationRate']) || $zone['sprinklerPrecipitationRate'] < 0.01 || $zone['sprinklerPrecipitationRate'] > 20.0) {
            return $this->failure(257, 'status.zone.invalid_precipitation_rate');
        }

        if (!isset($zone['Slope']) || $zone['Slope'] < 0 || $zone['Slope'] > 80) {
            return $this->failure(258, 'status.zone.invalid_slope');
        }

        return $this->success();
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitDailyInterval(int $startSeconds, int $endSeconds): array
    {
        $secondsPerDay = 86400;
        $normalizedStart = $startSeconds % $secondsPerDay;
        $normalizedEnd = $endSeconds % $secondsPerDay;

        if ($endSeconds <= $secondsPerDay) {
            return [[$normalizedStart, $endSeconds]];
        }

        return [
            [$normalizedStart, $secondsPerDay],
            [0, $normalizedEnd],
        ];
    }

    /**
     * @param array<string, mixed> $timeData
     */
    private function extractTimeComponent(array $timeData, string $key): int
    {
        if (!array_key_exists($key, $timeData) || !is_numeric($timeData[$key])) {
            return 0;
        }

        return (int) $timeData[$key];
    }

    /**
     * @param array<string, mixed> $timeData
     */
    private function isValidTimeOfDay(array $timeData): bool
    {
        if (!isset($timeData['hour'], $timeData['minute'], $timeData['second'])) {
            return false;
        }

        if (!is_numeric($timeData['hour']) || !is_numeric($timeData['minute']) || !is_numeric($timeData['second'])) {
            return false;
        }

        $hour = (int) $timeData['hour'];
        $minute = (int) $timeData['minute'];
        $second = (int) $timeData['second'];

        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59;
    }

    private function success(): ValidationResult
    {
        return ValidationResult::success();
    }

    private function failure(int $errorCode, string $translationKey): ValidationResult
    {
        return ValidationResult::failure($errorCode, $translationKey);
    }
}
