<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use DateTimeImmutable;
use Exception;

class EToHistoryUpdater
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Updates ETo history for the last 14 days (excluding today) and removes outdated entries.
     *
     * @param array<string, mixed> $locationData
     * @param array<string, float> $etoHistory
     * @return array{history: array<string, float>, hasChanges: bool, hasErrors: bool, dayErrors: array<int, array{translationKey: string, parameters: array<int, scalar>}>}
     */
    public function updateHistory(
        array $locationData,
        array $etoHistory,
        int $archiveID,
        int $tempID,
        int $humidityID,
        int $baroID,
        int $windID,
        float $windHeight,
        string $timezone
    ): array {
        $lat = $locationData['latitude'] ?? null;
        $lon = $locationData['longitude'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lon)) {
            throw new LocalizedInvalidArgumentException('error.eto_history.location_invalid');
        }

        $calculator = new EToCalculator($this->logger);
        $hasChanges = false;
        $hasErrors = false;
        $dayErrors = [];

        for ($i = 1; $i <= 14; $i++) {
            $targetDay = (new DateTimeImmutable('today'))->modify('-' . $i . ' day');
            $dateKey = $targetDay->format('Y-m-d');

            // Skip days that are already present in the history.
            if (isset($etoHistory[$dateKey])) {
                continue;
            }

            $this->logger?->debug('UpdateEToHistory', 'Missing values detected; starting calculation for day: ' . $dateKey);

            $startOfDay = $targetDay->setTime(0, 0, 0)->getTimestamp();
            $endOfDay = $targetDay->setTime(23, 59, 59)->getTimestamp();
            $archiveWindow = $this->describeArchiveWindow($startOfDay, $endOfDay);
            $archiveQuery = $this->describeArchiveQuery($archiveID, $tempID, $humidityID, $baroID, $windID, $startOfDay, $endOfDay);
            $this->logger?->debug('UpdateEToHistory', 'Checking archive data for ' . $dateKey . ' (' . $archiveWindow . ') with query: ' . $archiveQuery);
            $dayOfYear = (int) $targetDay->format('z') + 1;

            try {
                $dailyAggregates = $this->fetchDailyAggregates(
                    $archiveID,
                    $tempID,
                    $humidityID,
                    $baroID,
                    $windID,
                    $startOfDay,
                    $endOfDay
                );

                $tempValues = $dailyAggregates['temp'];
                $humidityValues = $dailyAggregates['humidity'];
                $baroValues = $dailyAggregates['baro'];
                $windValues = $dailyAggregates['wind'];

                $archiveDiagnostics = [
                    'temp'     => $this->summarizeArchiveVariable('temp', $tempValues, $tempID),
                    'humidity' => $this->summarizeArchiveVariable('humidity', $humidityValues, $humidityID),
                    'baro'     => $this->summarizeArchiveVariable('baro', $baroValues, $baroID),
                    'wind'     => $this->summarizeArchiveVariable('wind', $windValues, $windID),
                ];

                $missingArchiveFields = array_filter(
                    $archiveDiagnostics,
                    static fn (string $summary): bool => str_contains($summary, '0 entries') || str_contains($summary, 'missing fields')
                );
                if (count($missingArchiveFields) > 0) {
                    $details = implode('; ', $missingArchiveFields);
                    $this->logger?->debug(
                        'UpdateEToHistory',
                        'Archive gap for ' . $dateKey . ' (' . $archiveWindow . '): ' . $details . '; query=' . $archiveQuery
                    );
                    throw new LocalizedRuntimeException('error.eto_history.archive_data_incomplete', [$details, $archiveQuery]);
                }

                if (!isset($tempValues[0]['Min'], $tempValues[0]['Max']) ||
                    !isset($humidityValues[0]['Min'], $humidityValues[0]['Max']) ||
                    !isset($baroValues[0]['Avg']) ||
                    !isset($windValues[0]['Avg'])) {
                    $fieldIssues = [];
                    if (!isset($tempValues[0]['Min'], $tempValues[0]['Max'])) {
                        $fieldIssues[] = $archiveDiagnostics['temp'];
                    }
                    if (!isset($humidityValues[0]['Min'], $humidityValues[0]['Max'])) {
                        $fieldIssues[] = $archiveDiagnostics['humidity'];
                    }
                    if (!isset($baroValues[0]['Avg'])) {
                        $fieldIssues[] = $archiveDiagnostics['baro'];
                    }
                    if (!isset($windValues[0]['Avg'])) {
                        $fieldIssues[] = $archiveDiagnostics['wind'];
                    }

                    $details = implode('; ', $fieldIssues);
                    $this->logger?->debug(
                        'UpdateEToHistory',
                        'Missing archive fields for ' . $dateKey . ' (' . $archiveWindow . '): ' . $details . '; query=' . $archiveQuery
                    );
                    throw new LocalizedRuntimeException('error.eto_history.archive_structure_invalid', [$details, $archiveQuery]);
                }

                $archivePayload = [
                    'temp_min'     => (float) $tempValues[0]['Min'],
                    'temp_max'     => (float) $tempValues[0]['Max'],
                    'humidity_min' => (float) $humidityValues[0]['Min'],
                    'humidity_max' => (float) $humidityValues[0]['Max'],
                    'baro_avg'     => (float) $baroValues[0]['Avg'],
                    'wind_avg'     => (float) $windValues[0]['Avg']
                ];

                $calculatedETo = $this->calculateDailyETo(
                    $calculator,
                    (float) $lat,
                    (float) $lon,
                    $dateKey,
                    $dayOfYear,
                    $timezone,
                    $windHeight,
                    $archivePayload
                );

                $etoHistory[$dateKey] = round($calculatedETo, 2);
                $hasChanges = true;
            } catch (Exception $e) {
                $hasErrors = true;
                $errorDetail = $e->getMessage();
                if ($e instanceof LocalizedException ||
                    $e instanceof LocalizedInvalidArgumentException ||
                    $e instanceof LocalizedRuntimeException) {
                    $errorDetail = isset($e->parameters[0])
                        ? (string) $e->parameters[0]
                        : $e->translationKey;
                }
                $dayErrors[] = [
                    'translationKey' => 'error.eto_history.calculation_failed',
                    'parameters'     => [$dateKey, $errorDetail],
                ];
            }
        }

        $oldestAllowedDate = date('Y-m-d', strtotime('-14 day'));
        foreach ($etoHistory as $date => $value) {
            if ($date < $oldestAllowedDate) {
                unset($etoHistory[$date]);
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            ksort($etoHistory);
        }

        return [
            'history'    => $etoHistory,
            'hasChanges' => $hasChanges,
            'hasErrors'  => $hasErrors,
            'dayErrors'  => $dayErrors
        ];
    }

    /**
     * Fetches daily aggregated values from the archive handler for all required variables.
     *
     * @return array{temp: array<int, array<string, mixed>>, humidity: array<int, array<string, mixed>>, baro: array<int, array<string, mixed>>, wind: array<int, array<string, mixed>>}
     */
    protected function fetchDailyAggregates(
        int $archiveID,
        int $tempID,
        int $humidityID,
        int $baroID,
        int $windID,
        int $startOfDay,
        int $endOfDay
    ): array {
        $tempRaw = $this->readAggregatedValues($archiveID, $tempID, 1, $startOfDay, $endOfDay, 0);
        $humidityRaw = $this->readAggregatedValues($archiveID, $humidityID, 1, $startOfDay, $endOfDay, 0);
        $baroRaw = $this->readAggregatedValues($archiveID, $baroID, 1, $startOfDay, $endOfDay, 0);
        $windRaw = $this->readAggregatedValues($archiveID, $windID, 1, $startOfDay, $endOfDay, 0);

        return [
            'temp'     => $tempRaw,
            'humidity' => $humidityRaw,
            'baro'     => $baroRaw,
            'wind'     => $windRaw
        ];
    }

    /**
     * Reads aggregated archive values while suppressing transient IP-Symcon warnings
     * from broken aggregation files. The underlying archive is treated as a missing data source
     * instead of crashing the ETo history update.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function readAggregatedValues(
        int $archiveID,
        int $variableID,
        int $aggregationLevel,
        int $startTime,
        int $endTime,
        int $limit
    ): array {
        set_error_handler(static function (): bool
        {
            return true;
        });

        try {
            $result = AC_GetAggregatedValues($archiveID, $variableID, $aggregationLevel, $startTime, $endTime, $limit);
        } catch (Exception $e) {
            restore_error_handler();
            throw $e;
        }

        restore_error_handler();

        if (!is_array($result)) {
            $this->logger?->debug(
                'UpdateEToHistory',
                sprintf(
                    'Aggregated archive values for variable %d in period %s to %s are invalid or corrupted; using 0 entries.',
                    $variableID,
                    date('Y-m-d H:i:s', $startTime),
                    date('Y-m-d H:i:s', $endTime)
                )
            );
            return [];
        }

        return $result;
    }

    /**
     * Provides a compact diagnostic summary for archive entries to pinpoint missing values.
     *
     * @param array<int, array<string, mixed>> $values
     */
    protected function summarizeArchiveVariable(string $name, array $values, int $variableId): string
    {
        $prefix = $name . ' (ID ' . $variableId . ')';

        if (count($values) === 0) {
            return $prefix . ': 0 entries';
        }

        $firstEntry = $values[0];

        $keys = array_keys($firstEntry);
        $requiredKeys = [];
        if (in_array('Min', $keys, true) || in_array('Max', $keys, true)) {
            $requiredKeys = ['Min', 'Max'];
        }
        if (in_array('Avg', $keys, true)) {
            $requiredKeys = ['Avg'];
        }

        $missingFields = [];
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $firstEntry)) {
                $missingFields[] = $requiredKey;
            }
        }

        $summary = $prefix . ': ' . count($values) . ' entries, fields=' . implode(', ', $keys);
        if ($missingFields !== []) {
            $summary .= ', missing fields=' . implode(', ', $missingFields);
        }

        return $summary;
    }

    protected function describeArchiveWindow(int $startOfDay, int $endOfDay): string
    {
        return date('Y-m-d H:i:s', $startOfDay) . ' bis ' . date('Y-m-d H:i:s', $endOfDay);
    }

    protected function describeArchiveQuery(
        int $archiveID,
        int $tempID,
        int $humidityID,
        int $baroID,
        int $windID,
        int $startOfDay,
        int $endOfDay
    ): string {
        return 'AC_GetAggregatedValues('
            . 'archive=' . $archiveID
            . ', temp=' . $tempID
            . ', humidity=' . $humidityID
            . ', baro=' . $baroID
            . ', wind=' . $windID
            . ', aggregation=1, start=' . $startOfDay
            . ', end=' . $endOfDay
            . ', valueType=0)';
    }

    /**
     * Executes the daily ETo calculation using the provided calculator instance.
     *
     * @param array<string, float> $archivePayload
     */
    protected function calculateDailyETo(
        EToCalculator $calculator,
        float $lat,
        float $lon,
        string $dateKey,
        int $dayOfYear,
        string $timezone,
        float $windHeight,
        array $archivePayload
    ): float {
        return $calculator->calculateDailyETo(
            $lat,
            $lon,
            $dateKey,
            $dayOfYear,
            $timezone,
            $windHeight,
            $archivePayload,
            new SimpleHttpClient()
        );
    }
}
