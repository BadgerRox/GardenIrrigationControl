<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use Exception;
use InvalidArgumentException;

class WaterBalanceManager
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Calculates the scaled ETo for a specific zone based on solar exposure and slope gradient.
     *
     * Note: This method intentionally returns the raw, unrounded microclimate result.
     * The final rounding must happen at the end of the full ETc pipeline.
     *
     * @param float $baseETo The unscaled baseline ETo value for the day.
     * @param string $orientation The cardinal direction exposure (e.g. 'S', 'NO').
     * @param float $gradient The slope gradient in percent (e.g. 45.0).
     * @param bool $isSouthernHemisphere True if the zone is located on the southern hemisphere.
     * @return float The raw microclimate-scaled ETo depth in mm.
     */
    public function calculateScaledETo(float $baseETo, string $orientation, float $gradient, bool $isSouthernHemisphere = false): float
    {
        // 1. Defensive validation for base ETo
        if ($baseETo < 0.0) {
            $baseETo = 0.0;
        }

        $orientation = strtoupper(trim($orientation));
        if ($isSouthernHemisphere) {
            $orientation = $this->mirrorOrientationForSouthernHemisphere($orientation);
        }

        // 2. Solar exposure mapping (Compass direction factors).
        // The lookup remains north-hemisphere based; the southern hemisphere is
        // represented by a mirrored orientation so the same factor table stays valid.
        $sunFactors = [
            'N'  => 1.00,
            'E'  => 1.05,
            'S'  => 1.20,
            'W'  => 1.05,
            'NE' => 1.05,
            'SE' => 1.10,
            'SW' => 1.15,
            'NW' => 1.00
        ];

        // Fallback to 1.0 if an invalid or unknown direction string is provided.
        $sunFactor = $sunFactors[$orientation] ?? 1.0;

        // 3. Slope gradient calculation with safety boundaries (no negative slopes allowed)
        $cleanGradient = max(0.0, $gradient);
        $gradientFactor = (($cleanGradient / 2) / 100) + 1;

        // 4. Combined calculation without rounding (round as late as possible)
        return $baseETo * $sunFactor * $gradientFactor;
    }

    /**
     * Calculates the zone-specific daily ETo from source data and zone factors.
     *
     * The function resolves the target zone from the decoded configuration,
     * chooses either the historical daily ETo or the configured baseline as the
     * source value, validates the zone orientation and slope contract, and then
     * applies the microclimate scaling.
     *
     * @param int $valveVarId The IP-Symcon variable ID of the zone valve.
     * @param string $date The target calculation date formatted as "Y-m-d".
     * @param array<int|string, array<string, mixed>> $zonesTree The decoded configuration array representing the irrigation zones.
     * @param array<string, float> $etoHistory The decoded array containing historical unscaled ETo values (date => ETo in mm).
     * @param float $baselineETo The configured fallback baseline ETo value in mm.
     * @param float $kc The mandatory crop coefficient (Kc) for the configured system type.
     * @param bool $isSouthernHemisphere True if the zone is located on the southern hemisphere.
     * @return array{calculatedETo: float, usedFallback: bool} The final scaled ETo value and fallback usage flag.
     * @throws InvalidArgumentException If the provided date format or logic is invalid.
     * @throws Exception If the zone configuration is missing, incomplete, or contains invalid scaling parameters.
     */
    public function calculateScaledEToForZone(
        int $valveVarId,
        string $date,
        array $zonesTree,
        array $etoHistory,
        float $baselineETo,
        float $kc,
        bool $isSouthernHemisphere = false
    ): array {
        $this->parseStrictDate($date);

        if ($kc <= 0.0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.kc_invalid');
        }

        // Locate the specific zone inside the configuration tree
        $currentZone = null;
        foreach ($zonesTree as $zone) {
            if (isset($zone['ValveVarID']) && (int) $zone['ValveVarID'] === $valveVarId) {
                $currentZone = $zone;
                break;
            }
        }

        if (!$currentZone) {
            throw new LocalizedException('error.water_balance.zone_missing', [$valveVarId]);
        }

        // Determine the base ETo (prioritize historical API data over fallback baseline)
        $usedFallback = false;
        if (isset($etoHistory[$date])) {
            $baseETo = (float) $etoHistory[$date];
        } else {
            $usedFallback = true;
            $baseETo = $baselineETo;
            $this->logger?->debug('calculateScaledEToForZone', 'ETo: no historical ETo data found for ' . $date . '. Using the configured baseline ETo instead.');

            // Hard failure if both the API data is missing and the baseline is unset/insufficient
            if ($baseETo <= 0.1) {
                throw new LocalizedException('error.water_balance.baseline_eto_invalid');
            }
        }

        // Extract microclimate parameters of the specific zone
        if (!isset($currentZone['Orientation']) || !isset($currentZone['Slope'])) {
            throw new LocalizedException('error.water_balance.zone_parameters_missing', [$valveVarId]);
        }

        $orientation = strtoupper(trim((string) $currentZone['Orientation']));
        $validOrientations = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        if (!in_array($orientation, $validOrientations, true)) {
            throw new LocalizedException('error.water_balance.orientation_invalid', [$valveVarId, (string) $currentZone['Orientation']]);
        }

        if (!is_numeric($currentZone['Slope'])) {
            throw new LocalizedException('error.water_balance.slope_non_numeric', [$valveVarId]);
        }

        $slope = (float) $currentZone['Slope'];
        if ($slope < 0.0 || $slope > 80.0) {
            throw new LocalizedException('error.water_balance.slope_invalid', [$valveVarId, $slope]);
        }

        $this->logger?->debug('calculateScaledEToForZone', 'ValveID: ' . $valveVarId);
        $this->logger?->debug('calculateScaledEToForZone', 'ETo: ' . $baseETo . ' mm');
        $this->logger?->debug('calculateScaledEToForZone', 'Ausrichtung: ' . $orientation);
        $this->logger?->debug('calculateScaledEToForZone', 'Slope/gradient: ' . $slope . '%');

        // Execute mathematical scaling calculations
        $microclimateScaledETo = $this->calculateScaledETo($baseETo, $orientation, $slope, $isSouthernHemisphere);

        /*
         * WHY WE USE THE CROP COEFFICIENT (Kc):
         * 1. The daily ETo history stores standard global reference values based strictly on
         *    FAO-56 reference conditions (well-watered short grass surface, albedo 0.23).
         * 2. Real plant systems (e.g., shrubs or raised beds) differ significantly in actual
         *    evapotranspiration due to canopy structure, aerodynamic roughness, and crop traits.
         * 3. To keep the archived weather-based ETo history stable and physically consistent,
         *    we do not rewrite those values with system-local factors. Instead, we apply Kc at
         *    runtime to derive crop evapotranspiration for irrigation control:
         *    ETc (Crop Evapotranspiration) = ETo (Reference) * Kc
         */
        $calculatedETo = round($microclimateScaledETo * $kc, 4);

        $this->logger?->debug('calculateScaledEToForZone', 'Microclimate ETo (without Kc): ' . $microclimateScaledETo . ' mm');
        $this->logger?->debug('calculateScaledEToForZone', 'Crop coefficient (Kc): ' . $kc);
        $this->logger?->debug('calculateScaledEToForZone', 'Calculated ETc (with Kc): ' . $calculatedETo . ' mm');
        $this->logger?->debug('calculateScaledEToForZone', 'Fallback used: ' . ($usedFallback ? 'yes' : 'no'));

        return [
            'calculatedETo' => $calculatedETo,
            'usedFallback'  => $usedFallback
        ];
    }

    /**
     * Reads and returns the rainfall quantity for a time period.
     *
     * Return semantics:
     * - logged rainfall intensities are integrated over the requested period
     * - if no logged value exists in the last 24 hours before the period start, the baseline is 0.0 mm/h
     * - technical or validation problems throw an exception
     *
     * @param int $archiveId The archive handler instance ID.
     * @param int $rainVarId The IP-Symcon variable ID of the rain sensor value.
     * @param string $startDateTime The start timestamp of the period.
     * @param string $endDateTime The end timestamp of the period.
     * @return float The rainfall quantity in mm for the requested period.
     * @throws InvalidArgumentException If timestamps or IDs are invalid.
     * @throws Exception If archive data cannot be interpreted.
     */
    public function getRainfallForDate(int $archiveId, int $rainVarId, string $startDateTime, string $endDateTime): float
    {
        return (new RainHistoryCalculator($this->logger))->getRainfallForDate(
            $archiveId,
            $rainVarId,
            $startDateTime,
            $endDateTime
        );
    }

    /**
     * Reads rainfall amount from a day-counter variable for the day of EndDateTime.
     *
     * Rules:
     * - use local time for day boundaries
     * - search only within the same day as EndDateTime
     * - use the value exactly at EndDateTime or the latest earlier value of that day
     * - if no value exists that day, return 0.0
     *
     * @param int $archiveId The archive handler instance ID.
     * @param int $rainDayVarId The IP-Symcon variable ID of the day counter rain value.
     * @param string $endDateTime The end timestamp of the period.
     * @return float The rainfall quantity in mm for the day counter at EndDateTime.
     * @throws InvalidArgumentException If input is invalid.
     * @throws Exception If archive data cannot be interpreted.
     */
    public function getRainfallFromDayCounter(int $archiveId, int $rainDayVarId, string $endDateTime): float
    {
        if ($archiveId <= 0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.archive_id_invalid');
        }

        if ($rainDayVarId <= 0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.rain_day_variable_id_invalid');
        }

        $endDate = $this->parseStrictDateTime($endDateTime, 'Endzeitpunkt');

        $dayStart = $endDate->setTime(0, 0, 0)->getTimestamp();
        $endTimestamp = $endDate->getTimestamp();

        $this->logger?->debug(
            'getRainfallFromDayCounter',
            sprintf('[Mode: Counter] Reading daily counter rainfall for the day from %s to %s (VarID: %d, archive ID: %d).', date('Y-m-d H:i:s', $dayStart), $endDateTime, $rainDayVarId, $archiveId)
        );

        $loggedValues = AC_GetLoggedValues($archiveId, $rainDayVarId, $dayStart, $endTimestamp, 10000);
        if (!is_array($loggedValues)) {
            throw new LocalizedException('error.water_balance.rain_counter_archive_response_invalid');
        }

        if (count($loggedValues) === 0) {
            $this->logger?->debug('getRainfallFromDayCounter', '[Mode: Counter] No daily counter values found for the selected day. Returning 0.0 mm.');
            return 0.0;
        }

        usort($loggedValues, static function (array $left, array $right): int
        {
            return ((int) ($left['TimeStamp'] ?? 0)) <=> ((int) ($right['TimeStamp'] ?? 0));
        });

        $lastValue = null;
        foreach ($loggedValues as $entry) {
            if (!isset($entry['TimeStamp'], $entry['Value']) || !is_numeric($entry['TimeStamp']) || !is_numeric($entry['Value'])) {
                throw new LocalizedException('error.water_balance.rain_counter_archive_record_invalid');
            }

            $timestamp = (int) $entry['TimeStamp'];
            if ($timestamp > $endTimestamp) {
                break;
            }

            $lastValue = (float) $entry['Value'];
        }

        if ($lastValue === null) {
            $this->logger?->debug('getRainfallFromDayCounter', '[Mode: Counter] No valid daily counter value found up to the end time. Returning 0.0 mm.');
            return 0.0;
        }

        $rainfall = max(0.0, $lastValue);
        $this->logger?->debug('getRainfallFromDayCounter', sprintf('[Mode: Counter] Daily counter rainfall at the end time: %.4f mm.', $rainfall));

        return round($rainfall, 4);
    }

    /**
     * Calculates the water consumption for a valve over a time range.
     *
     * The method resolves the matching zone from the configuration tree,
     * reads the archived boolean valve states, calculates the total open
     * runtime in minutes, and converts the runtime into water consumption
     * by multiplying it with the zone's flow rate.
     *
     * If no archive value exists before the start time, the valve is treated
     * as closed until the first explicit archive entry in the range.
     *
     * @param int $archiveId The archive handler instance ID.
     * @param int $valveVarId The IP-Symcon variable ID of the valve.
     * @param string $startDateTime The start timestamp of the period.
     * @param string $endDateTime The end timestamp of the period.
     * @param array<int|string, array<string, mixed>> $zonesTree The decoded irrigation zones configuration.
     * @return float The calculated water consumption in mm.
     * @throws InvalidArgumentException If timestamps or IDs are invalid.
     * @throws Exception If the zone is missing, the archive data is invalid, or the flow rate is invalid.
     */
    public function calculateValveConsumption(
        int $archiveId,
        int $valveVarId,
        string $startDateTime,
        string $endDateTime,
        array $zonesTree
    ): float {
        if ($archiveId <= 0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.archive_id_invalid');
        }

        if ($valveVarId <= 0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.valve_variable_id_invalid');
        }

        $startDate = $this->parseStrictDateTime($startDateTime, 'Startzeitpunkt');
        $endDate = $this->parseStrictDateTime($endDateTime, 'Endzeitpunkt');

        if ($endDate <= $startDate) {
            throw new LocalizedInvalidArgumentException('error.water_balance.end_before_start');
        }

        $currentZone = null;
        foreach ($zonesTree as $zone) {
            if (isset($zone['ValveVarID']) && (int) $zone['ValveVarID'] === $valveVarId) {
                $currentZone = $zone;
                break;
            }
        }

        if (!$currentZone) {
            throw new LocalizedException('error.water_balance.zone_missing', [$valveVarId]);
        }

        if (!isset($currentZone['sprinklerPrecipitationRate']) || !is_numeric($currentZone['sprinklerPrecipitationRate'])) {
            throw new LocalizedException('error.water_balance.precipitation_rate_non_numeric', [$valveVarId]);
        }

        $sprinklerPrecipitationRate = (float) $currentZone['sprinklerPrecipitationRate'];
        if ($sprinklerPrecipitationRate < 0.01 || $sprinklerPrecipitationRate > 20.0) {
            throw new LocalizedException('error.water_balance.precipitation_rate_invalid', [$valveVarId, $sprinklerPrecipitationRate]);
        }

        $startTimestamp = $startDate->getTimestamp();
        $endTimestamp = $endDate->getTimestamp();
        // Technically: bound the pre-period lookback to 48 hours.
        // Functional behavior: a valve state older than 48h before the period start is treated as closed (existing fallback), never scanned.
        // Domain rationale: irrigation valves are cycled at least daily; a 48h lookback is more than sufficient to find the last known state without scanning a year of archive data on every zone start.
        $lookbackStartTimestamp = max(0, $startTimestamp - (48 * 3600));

        $this->logger?->debug(
            'calculateValveConsumption',
            sprintf(
                'Reading valve state for period %s to %s (VarID: %d, archive ID: %d, precipitation rate: %.4f mm/min).',
                $startDateTime,
                $endDateTime,
                $valveVarId,
                $archiveId,
                $sprinklerPrecipitationRate
            )
        );

        $loggedValues = AC_GetLoggedValues($archiveId, $valveVarId, $lookbackStartTimestamp, $endTimestamp, 10000);
        if (!is_array($loggedValues)) {
            throw new LocalizedException('error.water_balance.valve_archive_response_invalid');
        }

        $filteredValues = [];
        foreach ($loggedValues as $entry) {
            if (!isset($entry['TimeStamp']) || !is_numeric($entry['TimeStamp'])) {
                continue;
            }

            $timeStamp = (int) $entry['TimeStamp'];
            if ($timeStamp >= $lookbackStartTimestamp && $timeStamp <= $endTimestamp) {
                $filteredValues[] = $entry;
            }
        }

        if (count($filteredValues) === 0) {
            $this->logger?->debug('calculateValveConsumption', sprintf('No valve archive data found for period %s to %s. Returning 0.0 mm.', $startDateTime, $endDateTime));
            return 0.0;
        }

        usort($filteredValues, static function (array $left, array $right): int
        {
            return (int) $left['TimeStamp'] <=> (int) $right['TimeStamp'];
        });

        $currentState = false;
        $stateKnown = false;
        foreach ($filteredValues as $entry) {
            if (!isset($entry['Value'])) {
                throw new LocalizedException('error.water_balance.valve_archive_record_invalid');
            }

            $entryTimestamp = (int) $entry['TimeStamp'];
            if ($entryTimestamp >= $startTimestamp) {
                break;
            }

            $currentState = $this->normalizeLoggedBooleanValue($entry['Value']);
            $stateKnown = true;
        }

        if (!$stateKnown) {
            $this->logger?->debug('calculateValveConsumption', sprintf('No valve state found before %s. Assuming the initial state is CLOSED.', $startDateTime));
        } else {
            $this->logger?->debug('calculateValveConsumption', sprintf('Inherited state before the start time: %s.', $currentState ? 'OPEN' : 'CLOSED'));
        }

        $runtimeSeconds = 0.0;
        $lastTimestamp = $startTimestamp;

        foreach ($filteredValues as $entry) {
            if (!array_key_exists('Value', $entry)) {
                throw new LocalizedException('error.water_balance.valve_archive_record_invalid');
            }

            $entryTimestamp = (int) $entry['TimeStamp'];
            if ($entryTimestamp < $startTimestamp) {
                continue;
            }

            if ($entryTimestamp > $endTimestamp) {
                break;
            }

            if ($currentState) {
                $runtimeSeconds += $entryTimestamp - $lastTimestamp;
            }

            $currentState = $this->normalizeLoggedBooleanValue($entry['Value']);
            $lastTimestamp = $entryTimestamp;
        }

        if ($currentState && $endTimestamp > $lastTimestamp) {
            $runtimeSeconds += $endTimestamp - $lastTimestamp;
        }

        $runtimeMinutes = $runtimeSeconds / 60.0;
        $consumption = round(max(0.0, $runtimeMinutes * $sprinklerPrecipitationRate), 4);

        $this->logger?->debug('calculateValveConsumption', sprintf('Runtime: %.4f minutes, applied water: %.4f mm.', $runtimeMinutes, $consumption));

        return $consumption;
    }

    /**
     * Updates the persisted per-zone water balance history for the given date window.
     *
     * Rules:
     * - keep only valves that still exist in the current ZonesTree
     * - keep only entries for the requested target dates
     * - recalculate entries when missing, when zoneEtoFallback is true,
     *   when rain is null, or when irrigation is null
     *
     * @param array<int|string, array<string, mixed>> $zonesTree Decoded zone configuration.
     * @param array<int|string, array<int, array<string, mixed>>> $currentHistory Persisted history keyed by valveVarId.
     * @param array<int, string> $targetDates Strict dates (Y-m-d) to persist.
     * @param callable $zoneEtoProvider fn(int $valveVarId, string $date): array{calculatedETo: float, usedFallback: bool}|array{}
     * @param callable $rainProvider fn(string $startDateTime, string $endDateTime): ?float
     * @param callable $irrigationProvider fn(int $valveVarId, string $startDateTime, string $endDateTime): ?float
     * @return array{history: array<int|string, array<int, array<string, mixed>>>, refreshedEntries: int}
     */
    public function buildWaterBalanceHistoryDataset(
        array $zonesTree,
        array $currentHistory,
        array $targetDates,
        callable $zoneEtoProvider,
        callable $rainProvider,
        callable $irrigationProvider
    ): array {
        $normalizedDates = [];
        foreach ($targetDates as $targetDate) {
            $normalizedDates[] = $this->parseStrictDate($targetDate)->format('Y-m-d');
        }

        $normalizedDates = array_values(array_unique($normalizedDates));
        sort($normalizedDates);

        $newHistory = [];
        $refreshedEntries = 0;

        foreach ($zonesTree as $zone) {
            if (!isset($zone['ValveVarID']) || !is_numeric($zone['ValveVarID'])) {
                continue;
            }

            $valveVarId = (int) $zone['ValveVarID'];
            if ($valveVarId <= 0) {
                continue;
            }

            $valveKey = $valveVarId;
            $existingHistoryEntries = array_key_exists($valveKey, $currentHistory)
                ? $currentHistory[$valveKey]
                : [];
            $existingEntriesByDate = $this->indexHistoryEntriesByDate($existingHistoryEntries);
            $newHistory[$valveKey] = [];

            foreach ($normalizedDates as $date) {
                $existingEntry = $existingEntriesByDate[$date] ?? null;
                $mustRefresh = $this->mustRefreshHistoryEntry($existingEntry);

                if (!$mustRefresh && is_array($existingEntry)) {
                    $newHistory[$valveKey][] = $this->normalizeHistoryEntry($date, $existingEntry);
                    continue;
                }

                $startDateTime = $date . ' 00:00:00';
                $endDateTime = $date . ' 23:59:59';

                $zoneEtoResult = $zoneEtoProvider($valveVarId, $date);
                $zoneEto = null;
                $zoneEtoFallback = true;
                if (is_array($zoneEtoResult)
                    && isset($zoneEtoResult['calculatedETo'], $zoneEtoResult['usedFallback'])
                    && is_numeric($zoneEtoResult['calculatedETo'])
                    && is_bool($zoneEtoResult['usedFallback'])
                ) {
                    $zoneEto = round((float) $zoneEtoResult['calculatedETo'], 4);
                    $zoneEtoFallback = $zoneEtoResult['usedFallback'];
                }

                $rainResult = $rainProvider($startDateTime, $endDateTime);
                $rain = is_numeric($rainResult) ? round((float) $rainResult, 4) : null;

                $irrigationResult = $irrigationProvider($valveVarId, $startDateTime, $endDateTime);
                $irrigation = is_numeric($irrigationResult) ? round((float) $irrigationResult, 4) : null;

                $newHistory[$valveKey][] = [
                    'date'            => $date,
                    'zoneEto'         => $zoneEto,
                    'zoneEtoFallback' => $zoneEtoFallback,
                    'rain'            => $rain,
                    'irrigation'      => $irrigation,
                ];

                $refreshedEntries++;
            }
        }

        return [
            'history'          => $newHistory,
            'refreshedEntries' => $refreshedEntries,
        ];
    }

    /**
     * Builds and updates the persistent per-zone water storage anchor dataset.
     *
     * Storage semantics:
     * - negative values represent water deficit (missing water)
     * - positive values represent temporary water surplus
     * - storage is clamped to [-maxDeficit, +15.0] mm
     *
     * bookedDays stores per-day deltas inside the active window.
     *
     * Recalculation strategy:
     * - storageBeforeWindow is treated as the fixed start state before the first
     *   day of the current target window.
     * - the storage value is rebuilt day-by-day from that start state using the
     *   current history entries and daily clamping.
     * - this avoids non-linear clamp artifacts that can occur with pure delta-diff
     *   updates when previous runs were already saturated at bounds.
     *
     * @param array<int|string, array<string, mixed>> $zonesTree Decoded zone configuration.
     * @param array<int|string, array<int, array<string, mixed>>> $history Current 14-day water balance history keyed by valveVarId.
     * @param array<int|string, array<string, mixed>> $currentAnchor Persisted anchor dataset keyed by valveVarId.
     * @param array<int, string> $targetDates Strict dates (Y-m-d) used as bookedDays retention window.
     * @param float $maxDeficit Maximum allowed deficit in mm (positive number).
     * @return array{anchor: array<int|string, array<string, mixed>>, updatedZones: int}
     */
    public function buildWaterStorageAnchorDataset(
        array $zonesTree,
        array $history,
        array $currentAnchor,
        array $targetDates,
        float $maxDeficit
    ): array {
        if ($maxDeficit <= 0.0) {
            throw new LocalizedInvalidArgumentException('error.water_balance.max_deficit_invalid');
        }

        $normalizedDates = [];
        foreach ($targetDates as $targetDate) {
            $normalizedDates[] = $this->parseStrictDate($targetDate)->format('Y-m-d');
        }

        $normalizedDates = array_values(array_unique($normalizedDates));
        sort($normalizedDates);

        $newAnchor = [];
        $updatedZones = 0;

        foreach ($zonesTree as $zone) {
            if (!isset($zone['ValveVarID']) || !is_numeric($zone['ValveVarID'])) {
                continue;
            }

            $valveVarId = (int) $zone['ValveVarID'];
            if ($valveVarId <= 0) {
                continue;
            }

            $valveKey = $valveVarId;
            $zoneHistoryEntries = array_key_exists($valveKey, $history)
                ? $history[$valveKey]
                : [];
            $zoneEntriesByDate = $this->indexHistoryEntriesByDate($zoneHistoryEntries);
            $zoneAnchor = array_key_exists($valveKey, $currentAnchor)
                ? $currentAnchor[$valveKey]
                : [];

            $existingStorage = isset($zoneAnchor['storage']) && is_numeric($zoneAnchor['storage']) ? (float) $zoneAnchor['storage'] : 0.0;
            $storageBeforeWindow = isset($zoneAnchor['storageBeforeWindow']) && is_numeric($zoneAnchor['storageBeforeWindow'])
                ? (float) $zoneAnchor['storageBeforeWindow']
                : $existingStorage;
            $storageBeforeWindow = $this->clampWaterStorage($storageBeforeWindow, $maxDeficit);

            $anchorDate = isset($zoneAnchor['anchorDate']) && is_string($zoneAnchor['anchorDate']) ? $zoneAnchor['anchorDate'] : '';
            $lastAutoIrrigationDate = isset($zoneAnchor['lastAutoIrrigationDate']) && is_string($zoneAnchor['lastAutoIrrigationDate']) ? $zoneAnchor['lastAutoIrrigationDate'] : '';
            $previousBookedDays = $this->normalizeAnchorBookedDays($zoneAnchor['bookedDays'] ?? []);

            $firstTargetDate = $normalizedDates[0] ?? null;
            if ($firstTargetDate !== null) {
                $bookedDates = array_keys($previousBookedDays);
                sort($bookedDates);

                // Advance the window-start state by replaying days that moved out of
                // the active target window since the previous run.
                foreach ($bookedDates as $bookedDate) {
                    if ($bookedDate >= $firstTargetDate) {
                        break;
                    }

                    $storageBeforeWindow += (float) $previousBookedDays[$bookedDate]['appliedDelta'];
                    $storageBeforeWindow = $this->clampWaterStorage($storageBeforeWindow, $maxDeficit);
                }
            }

            $storage = $storageBeforeWindow;
            $bookedDays = [];

            foreach ($normalizedDates as $date) {
                if (!isset($zoneEntriesByDate[$date])) {
                    continue;
                }

                $entry = $this->normalizeHistoryEntry($date, $zoneEntriesByDate[$date]);

                $zoneEto = is_numeric($entry['zoneEto']) ? (float) $entry['zoneEto'] : 0.0;
                $rain = is_numeric($entry['rain']) ? (float) $entry['rain'] : 0.0;
                $irrigation = is_numeric($entry['irrigation']) ? (float) $entry['irrigation'] : 0.0;
                $appliedDelta = round($rain + $irrigation - $zoneEto, 4);

                $storage += $appliedDelta;
                $storage = $this->clampWaterStorage($storage, $maxDeficit);

                $isFinal = $entry['zoneEtoFallback'] === false
                    && $entry['rain'] !== null
                    && $entry['irrigation'] !== null;

                $bookedDays[$date] = [
                    'appliedDelta' => $appliedDelta,
                    'isFinal'      => $isFinal,
                ];

                $anchorDate = $date;
            }

            ksort($bookedDays);

            $newAnchor[$valveKey] = [
                'storageBeforeWindow'    => round($storageBeforeWindow, 4),
                'storage'                => round($storage, 4),
                'anchorDate'             => $anchorDate,
                'lastAutoIrrigationDate' => $lastAutoIrrigationDate,
                'bookedDays'             => $bookedDays,
            ];

            $updatedZones++;
        }

        return [
            'anchor'       => $newAnchor,
            'updatedZones' => $updatedZones,
        ];
    }

    /**
     * Mirrors a compass orientation for the southern hemisphere.
     *
     * East and west stay unchanged, while north/south-facing exposures are
     * swapped because the sun path is reversed across hemispheres.
     *
     * @param string $orientation The normalized compass direction.
     * @return string The mirrored direction for southern hemisphere use.
     */
    protected function mirrorOrientationForSouthernHemisphere(string $orientation): string
    {
        $mirror = [
            'N'  => 'S',
            'NE' => 'SE',
            'E'  => 'E',
            'SE' => 'NE',
            'S'  => 'N',
            'SW' => 'NW',
            'W'  => 'W',
            'NW' => 'SW',
        ];

        return $mirror[$orientation] ?? $orientation;
    }

    /**
     * Decides whether an existing history entry must be recalculated.
     *
     * Recalculation is required when the entry is missing/incomplete,
     * when ETo fallback was used, or when rain/irrigation values are absent.
     *
     * @param mixed $entry Existing entry candidate for one date.
     * @return bool True if the entry must be refreshed from providers.
     */
    protected function mustRefreshHistoryEntry(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return true;
        }

        if (!array_key_exists('zoneEtoFallback', $entry) || !is_bool($entry['zoneEtoFallback'])) {
            return true;
        }

        if ($entry['zoneEtoFallback'] === true) {
            return true;
        }

        if (!array_key_exists('rain', $entry) || $entry['rain'] === null) {
            return true;
        }

        if (!array_key_exists('irrigation', $entry) || $entry['irrigation'] === null) {
            return true;
        }

        return false;
    }

    /**
     * Converts a valve history entry list into a date-indexed map.
     *
     * Invalid or non-strict dates are ignored. If duplicate dates exist,
     * the last valid occurrence wins for that date key.
     *
     * @param mixed $entries Raw history entries for one valve.
     * @return array<string, array<string, mixed>> Map keyed by Y-m-d.
     */
    protected function indexHistoryEntriesByDate(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $indexed = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['date']) || !is_string($entry['date'])) {
                continue;
            }

            try {
                $date = $this->parseStrictDate($entry['date'])->format('Y-m-d');
            } catch (InvalidArgumentException) {
                continue;
            }

            $indexed[$date] = $entry;
        }

        return $indexed;
    }

    /**
     * Normalizes a single history entry to the canonical output structure.
     *
     * Numeric fields are converted to float and rounded to four decimals.
     * Missing/invalid values are normalized to null, and fallback defaults
     * to true when not explicitly provided as boolean.
     *
     * @param string $date Target date in Y-m-d format.
     * @param array<string, mixed> $entry Raw entry values.
     * @return array<string, mixed> Canonical history entry.
     */
    protected function normalizeHistoryEntry(string $date, array $entry): array
    {
        $zoneEto = isset($entry['zoneEto']) && is_numeric($entry['zoneEto']) ? round((float) $entry['zoneEto'], 4) : null;
        $zoneEtoFallback = isset($entry['zoneEtoFallback']) && is_bool($entry['zoneEtoFallback']) ? $entry['zoneEtoFallback'] : true;
        $rain = isset($entry['rain']) && is_numeric($entry['rain']) ? round((float) $entry['rain'], 4) : null;
        $irrigation = isset($entry['irrigation']) && is_numeric($entry['irrigation']) ? round((float) $entry['irrigation'], 4) : null;

        return [
            'date'            => $date,
            'zoneEto'         => $zoneEto,
            'zoneEtoFallback' => $zoneEtoFallback,
            'rain'            => $rain,
            'irrigation'      => $irrigation,
        ];
    }

    /**
     * Normalizes anchor bookedDays to canonical structure.
     *
     * @param mixed $bookedDays Raw bookedDays value from persisted anchor.
     * @return array<string, array{appliedDelta: float, isFinal: bool}>
     */
    protected function normalizeAnchorBookedDays(mixed $bookedDays): array
    {
        if (!is_array($bookedDays)) {
            return [];
        }

        $normalized = [];
        foreach ($bookedDays as $date => $entry) {
            if (!is_string($date) || !is_array($entry)) {
                continue;
            }

            try {
                $normalizedDate = $this->parseStrictDate($date)->format('Y-m-d');
            } catch (InvalidArgumentException) {
                continue;
            }

            if (!isset($entry['appliedDelta']) || !is_numeric($entry['appliedDelta'])) {
                continue;
            }

            $normalized[$normalizedDate] = [
                'appliedDelta' => round((float) $entry['appliedDelta'], 4),
                'isFinal'      => isset($entry['isFinal']) && is_bool($entry['isFinal']) ? $entry['isFinal'] : false,
            ];
        }

        return $normalized;
    }

    /**
     * Clamps water storage to a configured lower bound and a fixed upper bound.
     *
     * Domain rationale:
     * - Upper bound (+15 mm): A short-term surplus above field-relevant storage is
     *   not treated as fully available for later days. During heavy rain, part of
     *   the water percolates into deeper soil layers and is no longer usable in the
     *   effective root zone. The cap prevents unrealistic carry-over of that surplus.
     * - Lower bound (-maxDeficit): Deficit is intentionally limited so long outages
     *   (e.g., irrigation disabled for many days) do not accumulate to extreme values
     *   that would force unrealistic catch-up irrigation in one recovery phase.
     *
     * @param float $storage Current water storage in mm.
     * @param float $maxDeficit Positive absolute deficit limit in mm.
     * @return float Clamped storage in mm.
     */
    protected function clampWaterStorage(float $storage, float $maxDeficit): float
    {
        // Clamp to agronomic operating window: [-maxDeficit, +15 mm].
        return round(min(15.0, max(-$maxDeficit, $storage)), 4);
    }

    /**
     * Parses and validates a strict calendar date in Y-m-d format.
     *
     * @param string $date The input date string.
     * @return \DateTimeImmutable The normalized date object.
     * @throws InvalidArgumentException If the format or calendar value is invalid.
     */
    protected function parseStrictDate(string $date): \DateTimeImmutable
    {
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsedDate === false || $this->hasDateParseErrors($errors) || $parsedDate->format('Y-m-d') !== $date) {
            throw new LocalizedInvalidArgumentException('error.water_balance.date_invalid', [$date]);
        }

        return $parsedDate;
    }

    /**
     * Parses and validates a strict timestamp in Y-m-d H:i:s format.
     *
     * @param string $dateTime The input timestamp string.
     * @param string $label Human-readable field name for the exception message.
     * @return \DateTimeImmutable The normalized timestamp object.
     * @throws InvalidArgumentException If the format or calendar value is invalid.
     */
    protected function parseStrictDateTime(string $dateTime, string $label): \DateTimeImmutable
    {
        $parsedDateTime = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsedDateTime === false || $this->hasDateParseErrors($errors) || $parsedDateTime->format('Y-m-d H:i:s') !== $dateTime) {
            throw new LocalizedInvalidArgumentException('error.water_balance.datetime_invalid', [$label, $dateTime]);
        }

        return $parsedDateTime;
    }

    /**
     * Checks DateTime parser diagnostics for warnings or errors.
     *
     * @param array<string, mixed>|false $errors The parser diagnostics returned by DateTimeImmutable::getLastErrors().
     * @return bool True if parsing reported warnings or errors.
     */
    protected function hasDateParseErrors(array|false $errors): bool
    {
        if ($errors === false) {
            return false;
        }

        return (($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0);
    }

    /**
     * Normalizes logged valve values to a strict boolean.
     *
     * @param mixed $value The archived value.
     * @return bool True if the archived value represents an open valve.
     */
    protected function normalizeLoggedBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered !== null) {
                return $filtered;
            }

            if (is_numeric($value)) {
                return (float) $value > 0.0;
            }
        }

        return false;
    }
}
