<?php

declare(strict_types=1);

namespace GardenIrrigationControl\Libs;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final class RainHistoryCalculator
{
    private ?DebugLoggerInterface $logger;

    public function __construct(?DebugLoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Integrates archived rainfall intensity over a requested period.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function getRainfallForDate(int $archiveId, int $rainVarId, string $startDateTime, string $endDateTime): float
    {
        if ($archiveId <= 0 || $rainVarId <= 0) {
            throw new LocalizedInvalidArgumentException('error.rain_history.variable_ids_invalid');
        }

        $startDate = $this->parseStrictDateTime($startDateTime, 'Startzeitpunkt');
        $endDate = $this->parseStrictDateTime($endDateTime, 'Endzeitpunkt');
        if ($endDate <= $startDate) {
            throw new LocalizedInvalidArgumentException('error.rain_history.end_before_start');
        }

        $startTimestamp = $startDate->getTimestamp();
        $endTimestamp = $endDate->getTimestamp();
        $lookbackStartTimestamp = $startDate->modify('-24 hours')->getTimestamp();
        $this->logger?->debug('getRainfallForDate', sprintf('[Mode: Intensity] Reading rainfall for period %s to %s (VarID: %d, archive ID: %d).', $startDateTime, $endDateTime, $rainVarId, $archiveId));
        $loggedValues = AC_GetLoggedValues($archiveId, $rainVarId, $lookbackStartTimestamp, $endTimestamp, 10000);
        if (!is_array($loggedValues)) {
            throw new LocalizedException('error.rain_history.invalid_archive_response');
        }

        $loggedValues = array_values(array_filter(
            $loggedValues,
            static fn (array $entry): bool => is_numeric($entry['TimeStamp'])
                && is_numeric($entry['Value'])
                && (int) $entry['TimeStamp'] >= $lookbackStartTimestamp
                && (int) $entry['TimeStamp'] <= $endTimestamp
        ));
        if ($loggedValues === []) {
            $this->logger?->debug('getRainfallForDate', sprintf('[Mode: Intensity] No rain archive data found for period %s to %s. Returning 0.0 mm.', $startDateTime, $endDateTime));
            return 0.0;
        }

        usort($loggedValues, static fn (array $left, array $right): int => ((int) $left['TimeStamp']) <=> ((int) $right['TimeStamp']));
        $currentIntensity = 0.0;
        foreach ($loggedValues as $loggedValue) {
            if ((int) $loggedValue['TimeStamp'] >= $startTimestamp) {
                break;
            }
            $currentIntensity = (float) $loggedValue['Value'];
        }

        $rainfall = 0.0;
        $lastTimestamp = $startTimestamp;
        foreach ($loggedValues as $loggedValue) {
            $entryTimestamp = (int) $loggedValue['TimeStamp'];
            if ($entryTimestamp < $startTimestamp) {
                continue;
            }
            if ($entryTimestamp > $endTimestamp) {
                break;
            }
            if ($entryTimestamp > $lastTimestamp) {
                $rainfall += $currentIntensity * (($entryTimestamp - $lastTimestamp) / 3600);
            }
            $currentIntensity = (float) $loggedValue['Value'];
            $lastTimestamp = $entryTimestamp;
        }
        if ($endTimestamp > $lastTimestamp) {
            $rainfall += $currentIntensity * (($endTimestamp - $lastTimestamp) / 3600);
        }

        $this->logger?->debug('getRainfallForDate', sprintf('[Mode: Intensity] Calculated rainfall for period %s to %s: %.4f mm.', $startDateTime, $endDateTime, $rainfall));
        return round(max(0.0, $rainfall), 4);
    }

    private function parseStrictDateTime(string $dateTime, string $label): DateTimeImmutable
    {
        $parsedDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsedDateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsedDateTime->format('Y-m-d H:i:s') !== $dateTime) {
            throw new LocalizedInvalidArgumentException('error.rain_history.invalid_datetime', [$label, $dateTime]);
        }
        return $parsedDateTime;
    }
}
